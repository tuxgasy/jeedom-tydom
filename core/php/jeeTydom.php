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
  } else if ($result['msg_type'] == 'msg_metadata') {
    foreach ($result['data'] as $item) {
      foreach ($item['endpoints'] as $endpoint) {
        if (count($endpoint['metadata']) > 0) {
          $eqLogicId = $endpoint['id'] . '_' . $item['id'];
          $eqLogic = eqLogic::byLogicalId($eqLogicId, 'tydom');
          if (!is_object($eqLogic)) {
            log::add('tydom', 'warning', _("Impossible de trouver l'équipement ID : ") . $eqLogicId);
            continue;
          }

          file_put_contents(__DIR__ . '/../../data/devices/metadata.' . $eqLogicId . '.json', json_encode($endpoint['metadata']));

          $eqLogicDefaultConf = tydom::getDefaultConfiguration($eqLogic->getConfiguration('first_usage'), $eqLogic->getConfiguration('last_usage'));
          log::add('tydom', 'debug', "default configuration equipement  : " . json_encode($conf));

          foreach ($endpoint['metadata'] as $metadata) {
            if (in_array($metadata['permission'], ['r', 'rw'])) {
              $cmdInfo = $eqLogic->getCmd(null, $metadata['name']);
              if (!is_object($cmdInfo)) {
                $cmdInfo = new tydomCmd();
                $cmdInfo->setLogicalId($metadata['name']);
                $cmdInfo->setName($metadata['name']);
                $cmdInfo->setDefaultConfiguration($eqLogicDefaultConf);
              }
              $cmdInfo->setEqLogic_id($eqLogic->getId());
              $cmdInfo->setType('info');

              switch ($metadata['type']) {
                case 'string':
                case 'numeric':
                  $cmdInfo->setSubType($metadata['type']);
                  break;
                case 'boolean':
                  $cmdInfo->setSubType('binary');
                  break;
                default:
                  if ($cmdInfo->getSubType() == null) {
                    $cmdInfo->setSubType('other');
                  }
                  break;
              }

              if (isset($metadata['unit'])) {
                switch ($metadata['unit']) {
                  case 'degC':
                    $cmdInfo->setUnite('°C');
                    break;
                  case 'boolean':
                    $cmdInfo->setUnite('');
                    break;
                  default:
                    $cmdInfo->setUnite($metadata['unit']);
                    break;
                }
              }

              try {
                $cmdInfo->save();
              } catch (Exception $e) {
                log::add('tydom', 'error', _('Echec de creation de commande info: ') . json_encode($metadata) . ' | Error: ' . $e->getMessage());
                $cmdInfo = null;
              }
            } else {
              $cmdInfo = null;
            }

            if (in_array($metadata['permission'], ['w', 'rw'])) {
              $cmdIdBase = 'set_'.$metadata['name'];
              if (isset($metadata['enum_values'])) {
                $cmdIds = [];
                foreach ($metadata['enum_values'] as $value) {
                  $cmdIds[] = $cmdIdBase.':'.$value;
                }
              } else {
                $cmdIds = [$cmdIdBase];
              }

              foreach ($cmdIds as $cmdId) {
                $cmdAction = $eqLogic->getCmd(null, $cmdId);
                if (!is_object($cmdAction)) {
                  $cmdAction = new tydomCmd();
                  $cmdAction->setLogicalId($cmdId);
                  $cmdAction->setName($cmdId);
                  $cmdAction->setDefaultConfiguration($eqLogicDefaultConf);
                }
                $cmdAction->setEqLogic_id($eqLogic->getId());
                $cmdAction->setType('action');
                $cmdAction->setSubType('other');

                if ($cmdInfo != null) {
                  $cmdAction->setValue($cmdInfo->getId());
                }

                if (isset($metadata['min']) and isset($metadata['max'])) {
                  $cmdAction->setSubType('slider');
                  $cmdAction->setConfiguration('minValue', $metadata['min']);
                  $cmdAction->setConfiguration('maxValue', $metadata['max']);
                }

                try {
                  $cmdAction->save();
                } catch (Exception $e) {
                  log::add('tydom', 'error', _('Echec de creation de commande action: ') . json_encode($metadata) . ' | Error: ' . $e->getMessage());
                }
              }
            }
          }
        }
      }
    }
  } else if ($result['msg_type'] == 'msg_data') {
    foreach ($result['data'] as $item) {
      foreach ($item['endpoints'] as $endpoint) {
        if (count($endpoint['data']) > 0) {
          $eqLogicId = $endpoint['id'] . '_' . $item['id'];
          $eqLogic = eqLogic::byLogicalId($eqLogicId, 'tydom');
          if (!is_object($eqLogic)) {
            log::add('tydom', 'warning', _("Impossible de trouver l'équipement ID : ") . $eqLogicId);
            continue;
          }

          foreach ($endpoint['data'] as $data) {
            $cmd = $eqLogic->getCmd(null, $data['name']);
            if (!is_object($cmd)) {
              log::add('tydom', 'warning', _("Impossible de trouver la commande ID : ") . $data['name']);
              continue;
            }

            $cmd->event($data['value']);
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
