<?php
/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

/* * ***************************Includes********************************* */
require_once __DIR__  . '/../../../../core/php/core.inc.php';

class tydom extends eqLogic {
  /*     * ***********************Methode static*************************** */

  public static function deamon_info() {
    $return = array();
    $return['log'] = __CLASS__;
    $return['state'] = 'nok';

    $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
    if (file_exists($pid_file)) {
      if (@posix_getsid(trim(file_get_contents($pid_file)))) {
        $return['state'] = 'ok';
      } else {
        shell_exec(system::getCmdSudo() . 'rm -rf ' . $pid_file . ' 2>&1 > /dev/null');
      }
    }

    $return['launchable'] = 'ok';
    $mode = config::byKey('mode', __CLASS__);
    $host = config::byKey('host', __CLASS__);
    $mac = config::byKey('mac', __CLASS__);
    $password = config::byKey('password', __CLASS__);
    if ($mode == 'local' and $host == '') {
      $return['launchable'] = 'nok';
      $return['launchable_message'] = __('L\'adresse IP n\'est pas configuré', __FILE__);
    } elseif ($mac == '') {
      $return['launchable'] = 'nok';
      $return['launchable_message'] = __('L\'adresse MAC n\'est pas configuré', __FILE__);
    } elseif ($password == '') {
      $return['launchable'] = 'nok';
      $return['launchable_message'] = __('Le mot de passe n\'est pas configuré', __FILE__);
    }

    return $return;
  }

  public static function deamon_start() {
    self::deamon_stop();

    $deamon_info = self::deamon_info();
    if ($deamon_info['launchable'] != 'ok') {
      throw new Exception(__('Veuillez vérifier la configuration', __FILE__));
    }

    $path = realpath(dirname(__FILE__) . '/../../resources/tydomd');
    $cmd = 'python3 '. $path . '/tydomd.py';
    $cmd .= ' --loglevel ' . log::convertLogLevel(log::getLogLevel(__CLASS__));
    $cmd .= ' --socketport ' . config::byKey('socketport', __CLASS__);
    $cmd .= ' --mode "' . trim(str_replace('"', '\"', config::byKey('mode', __CLASS__))) . '"';
    $cmd .= ' --host "' . trim(str_replace('"', '\"', config::byKey('host', __CLASS__))) . '"';
    $cmd .= ' --mac "' . trim(str_replace('"', '\"', config::byKey('mac', __CLASS__))) . '"';
    $cmd .= ' --password "' . trim(str_replace('"', '\"', config::byKey('password', __CLASS__))) . '"';
    $cmd .= ' --pid ' . jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
    $cmd .= ' --apikey ' . jeedom::getApiKey(__CLASS__);
    $cmd .= ' --callback ' . network::getNetworkAccess('internal', 'proto:127.0.0.1:port:comp') . '/plugins/' . __CLASS__ . '/core/php/jeeTydom.php';

    log::add(__CLASS__, 'info', __('Lancement démon', __FILE__));
    $result = exec($cmd . ' >> ' . log::getPathToLog('tydomd') . ' 2>&1 &');

    $i = 0;
    while ($i < 20) {
      $deamon_info = self::deamon_info();
      if ($deamon_info['state'] == 'ok') {
        break;
      }
      sleep(1);
      $i++;
    }
    if ($i >= 20) {
      log::add(__CLASS__, 'error', __('Impossible de lancer le démon, vérifiez le log', __FILE__), 'unableStartDeamon');
      return false;
    }
    message::removeAll(__CLASS__, 'unableStartDeamon');
    return true;
  }

  public static function deamon_stop() {
    $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
    if (file_exists($pid_file)) {
      $pid = intval(trim(file_get_contents($pid_file)));
      system::kill($pid);
    }
    system::kill('tydomd.py');
    sleep(1);
  }

  public static function sendto_daemon($params) {
    $deamon_info = self::deamon_info();
    if ($deamon_info['state'] != 'ok') {
      throw new Exception(_("Le démon n'est pas démarré"));
    }

    $params['apikey'] = jeedom::getApiKey(__CLASS__);
    $payLoad = json_encode($params);
    $socket = socket_create(AF_INET, SOCK_STREAM, 0);
    socket_connect($socket, '127.0.0.1', config::byKey('socketport', __CLASS__));
    socket_write($socket, $payLoad, strlen($payLoad));
    socket_close($socket);
  }

  public static function getDeviceInfo($eqLogicId) {
    $file = __DIR__ . '/../../data/devices/' . $eqLogicId . '.json';
    if (!file_exists($file)) {
      return array();
    }
    return json_decode(file_get_contents($file), true);
  }

  public static function synchronize() {
    self::sendto_daemon(['action' => 'sync']);
  }

  public static function cron() {
    foreach (eqLogic::byType(__CLASS__) as $eqLogic) {
      if ($eqLogic->getConfiguration('last_usage') == 'conso') {
        $eqLogic->refreshConso('energyInstant');
      }
    }
  }

  public static function cron5() {
    foreach (eqLogic::byType(__CLASS__) as $eqLogic) {
      if ($eqLogic->getConfiguration('last_usage') == 'conso') {
        $eqLogic->refreshConso();
      }
    }
  }

  public static function cronDaily() {
    self::synchronize();
  }

  /*     * *********************Méthodes d'instance************************* */

  public function getImage() {
    $img = 'plugins/tydom/core/config/devices/' . $this->getConfiguration('last_usage') . '.png';
    if (file_exists($img)) {
      return $img;
    }

    return 'plugins/tydom/plugin_info/tydom_icon.png';
  }

  public function refreshConso($endpointName = null) {
    if ($this->getConfiguration('last_usage') != 'conso') {
      throw new Exception(_('Equipement ne peut être rafraîchi'));
    }

    $cmds = $this->getCmd();
    foreach ($cmds as $cmd) {
      if ($cmd->getLogicalId() == 'refresh') {
        continue;
      }

      $device = explode('_', $this->getLogicalId(), 2);
      if (count($device) != 2) {
        log::add(__CLASS__, 'warning', _('Equipement logical ID incorrect : ') . $this->getLogicalId());
        continue;
      }

      if ($endpointName != null and $endpointName != $infos[0]) {
        continue;
      }

      $infos = explode('_', $cmd->getLogicalId(), 2);
      if (count($infos) != 2) {
        log::add(__CLASS__, 'warning', _('Commande logical ID incorrect : ') . $cmd->getLogicalId());
        continue;
      }

      $params = [
        'action' => 'poll',
        'device_id' => $device[1],
        'endpoint_id' => $device[0],
        'endpoint_name' => $infos[0],
        'parameters' => [],
      ];

      $parameters = explode('|', $infos[1]);
      foreach ($parameters as $parameter) {
        $p = explode(':', $parameter, 2);
        if (count($p) != 2) {
          log::add(__CLASS__, 'warning', _('Paramètres incorrectes : ') . $parameter . ' / ' .$cmd->getLogicalId());
          continue 2;
        }

        $params['parameters'][$p[0]] = $p[1];
      }

      self::sendto_daemon($params);
    }
  }
}

class tydomCmd extends cmd {
  /*     * *********************Methode d'instance************************* */

  // Exécution d'une commande
  public function execute($_options = array()) {
    if ($this->getLogicalId() == 'refresh') {
      $eqLogic = $this->getEqLogic();
      $eqLogic->refreshConso();
      return;
    }

    $action = explode('_', $this->getLogicalId(), 2);
    if (count($action) != 2) {
      throw new Exception(_('Commande logical ID incorrect'));
    }

    $eqLogic = $this->getEqLogic();
    $device = explode('_', $eqLogic->getLogicalId(), 2);
    if (count($device) != 2) {
      throw new Exception(_('Equipement logical ID incorrect'));
    }

    $value = null;
    $endpointValue = explode(':', $action[1], 2);
    if (count($endpointValue) == 2) {
      $action[1] = $endpointValue[0];
      $value = $endpointValue[1];
    }

    if ($value === null) {
      switch ($this->getSubType()) {
        case 'slider':
        case 'select':
          $value = $_options[$this->getSubType()];
          break;
        default:
          throw new Exception(_('Commande type inconnue : ') . json_encode($_options));
      }
    }

    $params = [
      'action' => $action[0],
      'endpoint_name' => $action[1],
      'device_id' => $device[1],
      'endpoint_id' => $device[0],
      'value' => $value,
    ];
    tydom::sendto_daemon($params);
  }
}
