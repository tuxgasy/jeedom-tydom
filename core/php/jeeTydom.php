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

require_once dirname(__FILE__) . "/../../../../core/php/core.inc.php";

if (!jeedom::apiAccess(init('apikey'), 'tydom')) {
  echo _('Vous n\'etes pas autorisé à effectuer cette action', __FILE__);
  die();
}

if (init('test') != '') {
  echo 'OK';
  die();
}

$result = json_decode(file_get_contents("php://input"), true);
if (!is_array($result)) {
  die();
}

log::add('tydom', 'debug', _('Donnée reçu: ') . json_encode($result));

if (isset($result['msg_type'])) {
  if ($result['msg_type'] == 'msg_info') {
    $eqLogicId = $result['data']['mac'];
    $eqLogic = eqLogic::byLogicalId($eqLogicId, 'tydom');
    if (!is_object($eqLogic)) {
      $eqLogic = new tydom();
      $eqLogic->setLogicalId($eqLogicId);
      $eqLogic->setName($result['data']['productName']);
      $eqLogic->setIsEnable(1);
      $eqLogic->setEqType_name('tydom');
    }
    $eqLogic->setConfiguration('last_usage', 'box');
    $eqLogic->save();

    file_put_contents(__DIR__ . '/../../data/devices/' . $eqLogicId . '.json', json_encode($result['data']));
  } else if ($result['msg_type'] == 'msg_config') {
    foreach ($result['data']['endpoints'] as $endpoint) {
      $eqLogicId = $endpoint['id_endpoint'] . '_' . $endpoint['id_device'];
      $eqLogic = eqLogic::byLogicalId($eqLogicId, 'tydom');
      if (!is_object($eqLogic)) {
        $eqLogic = new tydom();
        $eqLogic->setLogicalId($eqLogicId);
        $eqLogic->setName($endpoint['name']);
        $eqLogic->setIsEnable(1);
        $eqLogic->setEqType_name('tydom');
      }
      $eqLogic->setConfiguration('first_usage', $endpoint['first_usage']);
      $eqLogic->setConfiguration('last_usage', $endpoint['last_usage']);
      $eqLogic->save();

      if ($endpoint['last_usage'] == 'conso') {
        $cmd = $eqLogic->getCmd(null, 'refresh');
        if (!is_object($cmd)) {
          $cmd = new tydomCmd();
          $cmd->setLogicalId('refresh');
          $cmd->setIsVisible(1);
          $cmd->setName('refresh');
        }
        $cmd->setEqLogic_id($eqLogic->getId());
        $cmd->setType('action');
        $cmd->setSubType('other');
        $cmd->save();
      }

      file_put_contents(__DIR__ . '/../../data/devices/' . $eqLogicId . '.json', json_encode($endpoint));
    }
  } else if ($result['msg_type'] == 'msg_data') {
    foreach ($result['data'] as $item) {
      foreach ($item['endpoints'] as $endpoint) {
        if (count($endpoint['data']) > 0) {
          $eqLogicId = $endpoint['id'] . '_' . $item['id'];
          $eqLogic = eqLogic::byLogicalId($eqLogicId, 'tydom');
          if (!is_object($eqLogic)) {
            log::add('tydom', 'warning', _("Impossible de trouver l'équipement ID : ") . $eqLogic);
            continue;
          }

          foreach ($endpoint['data'] as $data) {
            $cmd = $eqLogic->getCmd(null, $data['name']);
            if (!is_object($cmd)) {
              $cmd = new tydomCmd();
              $cmd->setLogicalId($data['name']);
              $cmd->setIsVisible(1);
              $cmd->setName($data['name']);
            }
            $cmd->setEqLogic_id($eqLogic->getId());
            $cmd->setType('info');
            $cmd->setSubType('string');

            if ($eqLogic->getConfiguration('last_usage') == 'boiler') {
              switch ($data['name']) {
                case 'setpoint':
                case 'temperature':
                  $cmd->setSubType('numeric');
                  $cmd->setUnite('°C');
                  break;

                case 'absence':
                case 'antifrostOn':
                case 'batteryCmdDefect':
                case 'boostOn':
                case 'loadSheddingOn':
                case 'openingDetected':
                case 'presenceDetected':
                case 'productionDefect':
                case 'tempoOn':
                case 'tempSensorDefect':
                case 'tempSensorOpenCirc':
                case 'tempSensorShortCut':
                  $cmd->setSubType('binary');
                  break;
              }
            }

            if ($eqLogic->getConfiguration('last_usage') == 'light') {
              switch ($data['name']) {
                case 'level':
                  $cmd->setSubType('numeric');
                  break;

                case 'thermicDefect':
                case 'onFavPos':
                  $cmd->setSubType('binary');
                  break;
              }
            }

            $cmd->save();
            $cmd->event($data['value']);

            if ($eqLogic->getConfiguration('last_usage') == 'boiler') {
              if ($data['name'] == 'setpoint') {
                $cmdAction = $eqLogic->getCmd(null, 'set_'.$data['name']);
                if (!is_object($cmdAction)) {
                  $cmdAction = new tydomCmd();
                  $cmdAction->setLogicalId('set_'.$data['name']);
                  $cmdAction->setIsVisible(1);
                  $cmdAction->setName('set_'.$data['name']);
                }
                $cmdAction->setEqLogic_id($eqLogic->getId());
                $cmdAction->setValue($cmd->getId());
                $cmdAction->setType('action');
                $cmdAction->setSubType('slider');
                $cmdAction->setConfiguration('minValue', 10);
                $cmdAction->setConfiguration('maxValue', 30);
                $cmdAction->setUnite('°C');
                $cmdAction->save();
              } else if ($data['name'] == 'hvacMode') {
                $cmdAction = $eqLogic->getCmd(null, 'set_'.$data['name']);
                if (!is_object($cmdAction)) {
                  $cmdAction = new tydomCmd();
                  $cmdAction->setLogicalId('set_'.$data['name']);
                  $cmdAction->setIsVisible(1);
                  $cmdAction->setName('set_'.$data['name']);
                }
                $cmdAction->setEqLogic_id($eqLogic->getId());
                $cmdAction->setValue($cmd->getId());
                $cmdAction->setType('action');
                $cmdAction->setSubType('select');
                $cmdAction->setConfiguration('listValue', 'NORMAL|NORMAL;STOP|STOP;ANTI_FROST|ANTI_FROST');
                $cmdAction->save();
              }
            }

            if ($eqLogic->getConfiguration('last_usage') == 'light') {
              if ($data['name'] == 'level') {
                $cmdAction = $eqLogic->getCmd(null, 'set_'.$data['name']);
                if (!is_object($cmdAction)) {
                  $cmdAction = new tydomCmd();
                  $cmdAction->setLogicalId('set_'.$data['name']);
                  $cmdAction->setIsVisible(1);
                  $cmdAction->setName('set_'.$data['name']);
                }
                $cmdAction->setEqLogic_id($eqLogic->getId());
                $cmdAction->setValue($cmd->getId());
                $cmdAction->setType('action');
                $cmdAction->setSubType('slider');
                $cmdAction->setConfiguration('minValue', 0);
                $cmdAction->setConfiguration('maxValue', 100);
                $cmdAction->save();

                $cmdAction = $eqLogic->getCmd(null, 'set_'.$data['name'].':100');
                if (!is_object($cmdAction)) {
                  $cmdAction = new tydomCmd();
                  $cmdAction->setLogicalId('set_'.$data['name'].':100');
                  $cmdAction->setIsVisible(1);
                  $cmdAction->setName('On');
                }
                $cmdAction->setEqLogic_id($eqLogic->getId());
                $cmdAction->setValue($cmd->getId());
                $cmdAction->setType('action');
                $cmdAction->setSubType('other');
                $cmdAction->save();

                $cmdAction = $eqLogic->getCmd(null, 'set_'.$data['name'].':0');
                if (!is_object($cmdAction)) {
                  $cmdAction = new tydomCmd();
                  $cmdAction->setLogicalId('set_'.$data['name'].':0');
                  $cmdAction->setIsVisible(1);
                  $cmdAction->setName('Off');
                }
                $cmdAction->setEqLogic_id($eqLogic->getId());
                $cmdAction->setValue($cmd->getId());
                $cmdAction->setType('action');
                $cmdAction->setSubType('other');
                $cmdAction->save();
              }
            }
          }
        }
      }
    }
  } else if ($result['msg_type'] == 'msg_cdata') {
    foreach ($result['data'] as $item) {
      foreach ($item['endpoints'] as $endpoint) {
        if (count($endpoint['cdata']) > 0) {
          $eqLogicId = $endpoint['id'] . '_' . $item['id'];
          $eqLogic = eqLogic::byLogicalId($eqLogicId, 'tydom');
          if (!is_object($eqLogic)) {
            log::add('tydom', 'warning', _("Impossible de trouver l'équipement ID : ") . $eqLogic);
            continue;
          }

          foreach ($endpoint['cdata'] as $cdata) {
            if ($cdata['name'] == 'energyInstant') {
              if (count(array_diff(['unit'], array_keys($cdata['parameters']))) != 0) {
                log::add('tydom', 'error', _('Paramètres manquantes : ') . json_encode($cdata));
                continue;
              }

              $cmdId = $cdata['name'] . '_unit:' . $cdata['parameters']['unit'];
              $cmd = $eqLogic->getCmd(null, $cmdId);
              if (!is_object($cmd)) {
                $cmd = new tydomCmd();
                $cmd->setLogicalId($cmdId);
                $cmd->setIsVisible(1);
                $cmd->setIsHistorized(1);
                $cmd->setName($cmdId);
              }
              $cmd->setEqLogic_id($eqLogic->getId());
              $cmd->setType('info');
              $cmd->setSubType('numeric');

              if ($cdata['parameters']['unit'] == 'ELEC_A') {
                $cmd->setUnite('A');
                $cmd->setConfiguration('minValue', $cdata['values']['min']/100);
                $cmd->setConfiguration('maxValue', $cdata['values']['max']/100);
                $cmd->save();

                $cmd->event($cdata['values']['measure']/100);
              } else {
                $cmd->save();
                $cmd->event($cdata['values']['measure']);
              }
            } else if ($cdata['name'] == 'energyIndex') {
              if (count(array_diff(['dest'], array_keys($cdata['parameters']))) != 0) {
                log::add('tydom', 'error', _('Paramètres manquantes : ') . json_encode($cdata));
                continue;
              }

              $cmdId = $cdata['name'] . '_dest:' . $cdata['parameters']['dest'];
              $cmd = $eqLogic->getCmd(null, $cmdId);
              if (!is_object($cmd)) {
                $cmd = new tydomCmd();
                $cmd->setLogicalId($cmdId);
                $cmd->setIsVisible(1);
                $cmd->setIsHistorized(1);
                $cmd->setName($cmdId);
              }
              $cmd->setEqLogic_id($eqLogic->getId());
              $cmd->setType('info');
              $cmd->setSubType('numeric');

              if (preg_match('/^ELEC_/', $cdata['parameters']['dest']) === 1) {
                $cmd->setUnite('kWh');
                $cmd->save();

                $cmd->event($cdata['values']['counter']/1000);
              } else if (preg_match('/^GAS_/', $cdata['parameters']['dest']) === 1) {
                $cmd->setUnite('m3');
                $cmd->save();

                $cmd->event($cdata['values']['counter']/1000);
              } else {
                $cmd->save();
                $cmd->event($cdata['values']['counter']);
              }
            } else if ($cdata['name'] == 'energyDistrib') {
              if (count(array_diff(['src', 'period', 'periodOffset'], array_keys($cdata['parameters']))) != 0) {
                log::add('tydom', 'error', _('Paramètres manquantes : ') . json_encode($cdata));
                continue;
              }

              foreach ($cdata['values'] as $key => $value) {
                if ($key == 'date') {
                  continue;
                }

                $cmdId = $cdata['name'] . '_src:' . $cdata['parameters']['src'] . '|period:' . $cdata['parameters']['period'] . '|periodOffset:' . $cdata['parameters']['periodOffset'] . '|value:' . $key;
                $cmd = $eqLogic->getCmd(null, $cmdId);
                if (!is_object($cmd)) {
                  $cmd = new tydomCmd();
                  $cmd->setLogicalId($cmdId);
                  $cmd->setIsVisible(1);
                  $cmd->setIsHistorized(1);
                  $cmd->setName($cmdId);
                }
                $cmd->setEqLogic_id($eqLogic->getId());
                $cmd->setType('info');
                $cmd->setSubType('numeric');

                if (preg_match('/^ELEC_/', $key) === 1) {
                  $cmd->setUnite('kWh');
                  $cmd->save();

                  $cmd->event($value/1000);
                } else if (preg_match('/^GAS_/', $key) === 1) {
                  $cmd->setUnite('m3');
                  $cmd->save();

                  $cmd->event($value/1000);
                } else {
                  $cmd->save();
                  $cmd->event($value);
                }
              }
            } else {
              log::add('tydom', 'error', _('Données inconnues: ') . json_encode($endpoint['cdata']));
            }
          }
        }
      }
    }
  } else {
    log::add('tydom', 'error', _('Données inconnues: ') . json_encode($result));
  }
  die();
}

log::add('tydom', 'error', _('Donnée reçu inconnue: ') . json_encode($result));
