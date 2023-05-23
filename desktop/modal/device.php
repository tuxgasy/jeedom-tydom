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

if (!isConnect('admin')) {
  throw new Exception('{{401 - Accès non autorisé}}');
}

$eqLogic = tydom::byId(init('id'));
if (!is_object($eqLogic)) {
  throw new \Exception(__('Equipement introuvable : ', __FILE__) . init('id'));
}

$infos = tydom::getDeviceInfo($eqLogic->getLogicalId());
$metadata = tydom::getDeviceMetadata($eqLogic->getLogicalId());
$cmetadata = tydom::getDeviceCMetadata($eqLogic->getLogicalId());

?>

<ul class="nav nav-tabs" role="tablist">
  <li role="presentation" class="active"><a href="#deviceInfosTab" aria-controls="profile" role="tab" data-toggle="tab"><i class="fas fa-list-alt"></i> {{Informations brutes}}</a></li>
  <li role="presentation"><a href="#deviceMetadataTab" aria-controls="profile" role="tab" data-toggle="tab"><i class="fas fa-list-alt"></i> {{Métadonnées}}</a></li>
</ul>
<div class="tab-content">
  <div role="tabpanel" class="tab-pane active" id="deviceInfosTab">
    <pre><?php echo json_encode($infos, JSON_PRETTY_PRINT); ?></pre>
  </div>
  <div role="tabpanel" class="tab-pane" id="deviceMetadataTab">
    <pre><?php echo json_encode($metadata, JSON_PRETTY_PRINT); ?></pre>
  </div>
  <div role="tabpanel" class="tab-pane" id="deviceCMetadataTab">
    <pre><?php echo json_encode($cmetadata, JSON_PRETTY_PRINT); ?></pre>
  </div>
</div>
