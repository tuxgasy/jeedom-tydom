#!/usr/bin/env python3
#-*- coding: utf-8 -*-

import argparse
import asyncio
import json
import logging
import os
import signal
import socket
import sys

from jeedom.jeedom import *
from tydom.TydomClient import TydomClient
from tydom.MessageHandler import MessageHandler

async def read_socket():
  global JEEDOM_SOCKET_MESSAGE
  if not JEEDOM_SOCKET_MESSAGE.empty():
    logging.debug("Message received in socket JEEDOM_SOCKET_MESSAGE")
    message = json.loads(JEEDOM_SOCKET_MESSAGE.get())
    if message['apikey'] != _apikey:
      logging.error("Invalid apikey from socket : " + str(message))
      return

    if 'action' not in message:
      logging.error("Missing action value : " + str(message))
      return

    if message['action'] == 'sync':
      await tydom_client.setup()
      return

    if message['action'] == 'set':
      await tydom_client.put_devices_data(message['device_id'], message['endpoint_id'], message['endpoint_name'], message['value'])
      return

    if message['action'] == 'poll':
      url = '/devices/' + message['device_id'] + '/endpoints/' + message['endpoint_id'] + '/cdata?name=' + message['endpoint_name']
      for key, value in message['parameters'].items():
        if message['endpoint_name'] == 'energyDistrib' and key == 'value':
          continue
        url = url + '&' + key + '=' + value

      if message['endpoint_name'] == 'energyInstant' or message['endpoint_name'] == 'energyIndex':
        url = url + '&reset=false'

      await tydom_client.get_poll_device_data(url)
      return

async def listen_tydom():
  try:
    await tydom_client.connect()
    await tydom_client.setup()
    while 1:
      try:
        incoming_bytes_str = await tydom_client.connection.recv()
        message_handler = MessageHandler(
          incoming_bytes=incoming_bytes_str,
          tydom_client=tydom_client,
          jeedom_com=jeedom_com
        )
        await message_handler.incoming_triage()
      except Exception as e:
        logging.warning("Unable to handle message: %s", e)
  except socket.gaierror as e:
    logging.error("Socket error: %s", e)
    sys.exit(1)
  except ConnectionRefusedError as e:
    logging.error("Connection refused: %s", e)
    sys.exit(1)
  except Exception as e:
    logging.error("Error: %s", e)
    sys.exit(1)

async def listen_socket():
  jeedom_socket.open()

  try:
    while 1:
      await asyncio.sleep(0.5)
      try:
        await read_socket()
      except Exception as e:
        logging.error("Unable to read incoming message: %s", e)
  except KeyboardInterrupt:
    sys.exit(1)

# ----------------------------------------------------------------------------

async def shutdown(signal, loop):
  logging.info('Received exit signal %s', signal.name)

  try:
    jeedom_socket.close()
  except:
    pass

  try:
    jeedom_serial.close()
  except:
    pass

  try:
    # Close connections
    await tydom_client.disconnect()

    # Cancel async tasks
    tasks = []
    for task in asyncio.all_tasks():
      if task is not asyncio.current_task():
        task.cancel()
        tasks.append(task)
    await asyncio.gather(*tasks)
    logging.info("All running tasks cancelled")
  except Exception as e:
    logging.info("Some errors occurred when stopping tasks %s", e)
  finally:
    loop.stop()

  try:
    logging.debug("Removing PID file " + str(_pidfile))
    os.remove(_pidfile)
  except:
    pass

# ----------------------------------------------------------------------------

_log_level = "error"
_socket_port = 55200
_socket_host = 'localhost'
_mode = 'remote'
_host = ''
_mac = ''
_password = ''
_pidfile = '/tmp/tydomd.pid'
_apikey = ''
_callback = ''

parser = argparse.ArgumentParser()
parser.add_argument("--loglevel", help="Log Level for the daemon", type=str)
parser.add_argument("--socketport", help="Socketport for server", type=str)
parser.add_argument("--mode", help="Mode", type=str)
parser.add_argument("--host", help="Tydom IP", type=str)
parser.add_argument("--mac", help="Tydom MAC", type=str)
parser.add_argument("--password", help="Tydom password", type=str)
parser.add_argument("--pid", help="Pid file", type=str)
parser.add_argument("--apikey", help="Apikey", type=str)
parser.add_argument("--callback", help="Callback", type=str)
args = parser.parse_args()

if args.loglevel:
  _log_level = args.loglevel
if args.socketport:
  _socket_port = int(args.socketport)
if args.mode:
  _mode = args.mode
if args.host:
  _host = args.host
if args.mac:
  _mac = args.mac
if args.password:
  _password = args.password
if args.pid:
  _pidfile = args.pid
if args.apikey:
  _apikey = args.apikey
if args.callback:
  _callback = args.callback

_socket_port = int(_socket_port)

jeedom_utils.set_log_level(_log_level)

logging.info('Start demond')

tydom_client = TydomClient(
  mac=_mac,
  password=_password,
  host='mediation.tydom.com' if _mode == 'remote' else _host
)

jeedom_com = jeedom_com(apikey = _apikey, url = _callback)
if not jeedom_com.test():
  logging.error('Network communication issues. Please fixe your Jeedom network configuration.')
  sys.exit(1)

jeedom_socket = jeedom_socket(port=_socket_port,address=_socket_host)

def main():
  try:
    jeedom_utils.write_pid(str(_pidfile))

    loop = asyncio.new_event_loop()
    for s in [signal.SIGHUP, signal.SIGTERM, signal.SIGINT]:
      loop.add_signal_handler(s, lambda: asyncio.create_task(shutdown(s, loop)))

    loop.create_task(listen_tydom())
    loop.create_task(listen_socket())
    loop.run_forever()
  except Exception as e:
    logging.error('Fatal error: %s', e)
    logging.info(traceback.format_exc())

if __name__ == '__main__':
  main()
