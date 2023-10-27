#!/usr/bin/env python3
#-*- coding: utf-8 -*-

# From https://github.com/fmartinou/tydom2mqtt/blob/master/app/tydom/MessageHandler.py

import json
import logging
from http.client import HTTPResponse
from http.server import BaseHTTPRequestHandler
from io import BytesIO

logger = logging.getLogger(__name__)

class MessageHandler:

    def __init__(self, incoming_bytes, tydom_client, jeedom_com):
        self.incoming_bytes = incoming_bytes
        self.tydom_client = tydom_client
        self.jeedom_com = jeedom_com
        self.cmd_prefix = tydom_client.cmd_prefix

    async def incoming_triage(self):
        bytes_str = self.incoming_bytes
        incoming = None
        first = str(bytes_str[:40])
        try:
            if "Uri-Origin: /refresh/all" in first in first:
                pass
            elif ("PUT /devices/data" in first) or ("/devices/cdata" in first):
                logger.debug('PUT /devices/data message detected !')
                try:
                    try:
                        incoming = self.parse_put_response(bytes_str)
                    except BaseException:
                        # Tywatt response starts at 7
                        incoming = self.parse_put_response(bytes_str, 7)
                    await self.parse_response(incoming)
                except BaseException:
                    logger.error(
                        'Error when parsing devices/data tydom message (%s)',
                        bytes_str)
            elif ("scn" in first):
                try:
                    incoming = get(bytes_str)
                    await self.parse_response(incoming)
                    logger.debug('Scenarii message processed')
                except BaseException:
                    logger.error(
                        'Error when parsing Scenarii tydom message (%s)', bytes_str)
            elif ("POST" in first):
                try:
                    incoming = self.parse_put_response(bytes_str)
                    await self.parse_response(incoming)
                    logger.debug('POST message processed')
                except BaseException:
                    logger.error(
                        'Error when parsing POST tydom message (%s)', bytes_str)
            elif ("HTTP/1.1" in first):
                response = self.response_from_bytes(
                    bytes_str[len(self.cmd_prefix):])
                incoming = response.decode("utf-8")
                try:
                    await self.parse_response(incoming)
                except BaseException:
                    logger.error(
                        'Error when parsing HTTP/1.1 tydom message (%s)', bytes_str)
            else:
                logger.warning(
                    'Unknown tydom message type received (%s)', bytes_str)

        except Exception as e:
            logger.error('Technical error when parsing tydom message (%s)', str(e))
            logger.error('Tydom message (%s)', bytes_str)
            logger.debug('Incoming payload (%s)', incoming)

    # Basic response parsing. Typically GET responses + instanciate covers and
    # alarm class for updating data
    async def parse_response(self, incoming):
        data = incoming
        msg_type = None
        first = str(data[:40])

        if data != '':
            if "id_catalog" in data:
                msg_type = 'msg_config'
            elif "cmetadata" in data:
                msg_type = 'msg_cmetadata'
            elif "metadata" in data:
                msg_type = 'msg_metadata'
            elif "cdata" in data:
                msg_type = 'msg_cdata'
            elif "id" in first:
                msg_type = 'msg_data'
            elif "doctype" in first:
                msg_type = 'msg_html'
            elif "productName" in first:
                msg_type = 'msg_info'

            if msg_type is None:
                logger.warning('Unknown message type received (%s)', data)
            else:
                logger.debug('Message received detected as (%s)', msg_type)
                try:
                    if msg_type == 'msg_config':
                        parsed = json.loads(data)
                        self.jeedom_com.send_change_immediate({'msg_type': msg_type, 'data': parsed})

                    elif msg_type == 'msg_cmetadata':
                        parsed = json.loads(data)
                        self.jeedom_com.send_change_immediate({'msg_type': msg_type, 'data': parsed})
                        await self.parse_cmeta_data(parsed=parsed)

                    elif msg_type == 'msg_metadata':
                        parsed = json.loads(data)
                        self.jeedom_com.send_change_immediate({'msg_type': msg_type, 'data': parsed})

                    elif msg_type == 'msg_data':
                        parsed = json.loads(data)
                        self.jeedom_com.send_change_immediate({'msg_type': msg_type, 'data': parsed})

                    elif msg_type == 'msg_cdata':
                        parsed = json.loads(data)
                        self.jeedom_com.send_change_immediate({'msg_type': msg_type, 'data': parsed})

                    elif msg_type == 'msg_html':
                        pass

                    elif msg_type == 'msg_info':
                        parsed = json.loads(data)
                        self.jeedom_com.send_change_immediate({'msg_type': msg_type, 'data': parsed})

                    logger.debug('Incoming data parsed with success')
                except Exception as e:
                    logger.error('Error on parsing tydom response (%s)', e)
                    logger.error('Incoming data (%s)', data)

    async def parse_cmeta_data(self, parsed):
        for i in parsed:
            for endpoint in i["endpoints"]:
                if len(endpoint["cmetadata"]) > 0:
                    for elem in endpoint["cmetadata"]:
                        device_id = i["id"]
                        endpoint_id = endpoint["id"]

                        if elem["name"] == "energyIndex":
                            for params in elem["parameters"]:
                                if params["name"] == "dest":
                                    for dest in params["enum_values"]:
                                        url = "/devices/" + str(i["id"]) + "/endpoints/" + str(
                                            endpoint["id"]) + "/cdata?name=" + elem["name"] + "&dest=" + dest + "&reset=false"
                                        await self.tydom_client.get_poll_device_data(url)

                        elif elem["name"] == "energyInstant":
                            for params in elem["parameters"]:
                                if params["name"] == "unit":
                                    for unit in params["enum_values"]:
                                        url = "/devices/" + str(i["id"]) + "/endpoints/" + str(
                                            endpoint["id"]) + "/cdata?name=" + elem["name"] + "&unit=" + unit + "&reset=false"
                                        await self.tydom_client.get_poll_device_data(url)

                        elif elem["name"] == "energyDistrib":
                            for params in elem["parameters"]:
                                if params["name"] == "src":
                                    for src in params["enum_values"]:
                                        url = "/devices/" + str(i["id"]) + "/endpoints/" + str(
                                            endpoint["id"]) + "/cdata?name=" + elem["name"] + "&period=YEAR&periodOffset=0&src=" + src
                                        await self.tydom_client.get_poll_device_data(url)

                                        url = "/devices/" + str(i["id"]) + "/endpoints/" + str(
                                            endpoint["id"]) + "/cdata?name=" + elem["name"] + "&period=MONTH&periodOffset=0&src=" + src
                                        await self.tydom_client.get_poll_device_data(url)

                        elif elem["name"] == "energyHisto":
                            for params in elem["parameters"]:
                                if params["name"] == "dest":
                                    for dest in params["enum_values"]:
                                        url = "/devices/" + str(i["id"]) + "/endpoints/" + str(endpoint["id"]) + "/cdata?name=" + elem["name"] + "&period=YEAR&dest=" + dest
                                        await self.tydom_client.get_poll_device_data(url)

                                        url = "/devices/" + str(i["id"]) + "/endpoints/" + str(endpoint["id"]) + "/cdata?name=" + elem["name"] + "&period=YEARS&dest=" + dest
                                        await self.tydom_client.get_poll_device_data(url)

        logger.debug('Metadata configuration updated')

    # PUT response DIRTY parsing
    def parse_put_response(self, bytes_str, start=6):
        # TODO : Find a cooler way to parse nicely the PUT HTTP response
        resp = bytes_str[len(self.cmd_prefix):].decode("utf-8")
        fields = resp.split("\r\n")
        fields = fields[start:]  # ignore the PUT / HTTP/1.1
        end_parsing = False
        i = 0
        output = str()
        while not end_parsing:
            field = fields[i]
            if len(field) == 0 or field == '0':
                end_parsing = True
            else:
                output += field
                i = i + 2
        parsed = json.loads(output)
        return json.dumps(parsed)

    # FUNCTIONS

    @staticmethod
    def response_from_bytes(data):
        sock = BytesIOSocket(data)
        response = HTTPResponse(sock)
        response.begin()
        return response.read()

class BytesIOSocket:
    def __init__(self, content):
        self.handle = BytesIO(content)

    def makefile(self, mode):
        return self.handle


class HTTPRequest(BaseHTTPRequestHandler):
    def __init__(self, request_text):
        self.raw_requestline = request_text
        self.error_code = self.error_message = None
        self.parse_request()

    def send_error(self, code, message):
        self.error_code = code
        self.error_message = message
