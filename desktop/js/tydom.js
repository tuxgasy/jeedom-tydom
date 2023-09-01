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

$('#syncEqLogic').off('click').on('click', function() {
  $.ajax({
    type: 'post',
    url: 'plugins/tydom/core/ajax/tydom.ajax.php',
    data: {
      action: 'sync',
    },
    dataType: 'json',
    global: false,
    error: function (request, status, error) {
      handleAjaxError(request, status, error);
    },
    success: function (data) {
      $('#div_alert').showAlert({message: '{{Synchronisation en cours}}', level: 'warning'});
    },
  });
});

$('.eqLogicAttr[data-l1key=configuration][data-l2key=last_usage]').off('change').on('change', function() {
  if ($(this).value()) {
    $('#img_device').attr('src', 'plugins/tydom/core/config/devices/' + $(this).value() + '.png');
  }
});

$('#bt_showTydomDevice').off('change').on('click', function() {
  $('#md_modal').dialog({title: "{{Configuration du noeud}}"}).load('index.php?v=d&plugin=tydom&modal=device&id='+$('.eqLogicAttr[data-l1key=id]').value()).dialog('open');
});

/* Permet la réorganisation des commandes dans l'équipement */
$("#table_cmd").sortable({
  axis: "y",
  cursor: "move",
  items: ".cmd",
  placeholder: "ui-state-highlight",
  tolerance: "intersect",
  forcePlaceholderSize: true
});
