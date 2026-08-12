<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Brazilian Portuguese language strings.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['affinityfield'] = 'Agrupar por campo de perfil';
$string['affinityfield_help'] = 'Distribui os participantes aplicando a estratégia escolhida ao valor deste campo. Campos nativos do usuário e campos de perfil personalizados aparecem juntos na lista. Participantes sem valor no campo são alocados aleatoriamente e aparecem como aviso na pré-visualização.';
$string['affinitymode'] = 'Estratégia de afinidade';
$string['affinitymode_help'] = 'Manter juntos: participantes com o mesmo valor vão para o mesmo grupo. Manter separados: o alocador evita repetir o mesmo valor dentro de um grupo. Ambas valem para os participantes distribuídos nesta execução; valores de membros que o grupo já possui não são considerados.';
$string['affinitymodeapart'] = 'Manter separados — evita participantes com o mesmo valor no mesmo grupo';
$string['affinitymodetogether'] = 'Manter juntos — participantes com o mesmo valor vão para o mesmo grupo';
$string['affinitynone'] = 'Não agrupar (padrão)';
$string['affinitysection'] = 'Afinidade por campo de perfil';
$string['allocationsection'] = 'Alocação';
$string['appliedsummary'] = 'Distribuição aplicada: {$a->added} participações em {$a->groups} grupos.';
$string['applydistribution'] = 'Aplicar distribuição';
$string['applyfinished'] = 'A distribuição foi concluída. Confira o resultado na página de grupos.';
$string['applyfootnote'] = 'Nada é gravado até você aplicar. Volumes grandes são aplicados em segundo plano com barra de progresso.';
$string['applymessagestale'] = 'Distribuição de participantes NÃO aplicada';
$string['applymessagestalebody'] = 'Inscrições ou grupos mudaram entre a sua pré-visualização e a execução em segundo plano; nada foi gravado. Pré-visualize e aplique a distribuição novamente.';
$string['applymessagesuccess'] = 'Distribuição de participantes aplicada';
$string['applymessagesuccessbody'] = 'A distribuição em segundo plano terminou: {$a->added} participações em {$a->groups} grupos.';
$string['applyprogress'] = 'Gravando participações nos grupos';
$string['applyrunning'] = 'A distribuição está sendo aplicada em segundo plano. Você pode sair desta página; as participações continuam sendo gravadas.';
$string['applytoall'] = 'Aplicar a todos';
$string['backadjust'] = 'Voltar e ajustar';
$string['bulkeditfootnote'] = 'Somente os campos personalizados são gravados aqui. Nome, descrição e demais configurações abrem em "Editar".';
$string['bulkeditgroups'] = 'Editar grupos em lote';
$string['chosenoptions'] = 'Opções escolhidas';
$string['cleanupfieldsonuninstall'] = 'Remover campos de grupo ao desinstalar';
$string['cleanupfieldsonuninstall_desc'] = 'Se habilitado, desinstalar o plugin exclui os campos personalizados de grupo provisionados por ele (vagas e local) junto com os valores armazenados em cada grupo. Se desabilitado, os campos e seus dados são mantidos e podem ser gerenciados nos campos personalizados de grupo.';
$string['columnsbutton'] = 'Colunas';
$string['columnsmenuhead'] = 'Grupo e "{$a}" ficam sempre visíveis. Outros campos personalizados de grupo entram nesta lista automaticamente.';
$string['distributeparticipants'] = 'Distribuir participantes';
$string['editgroupsettings'] = 'Configurações do grupo — {$a}';
$string['errornogroups'] = 'Selecione ao menos um grupo existente para distribuir os participantes.';
$string['errornogroupsedit'] = 'Selecione ao menos um grupo existente para editar.';
$string['erroroverbookrange'] = 'O overbooking deve estar entre 0 e 99.';
$string['errorstale'] = 'Inscrições ou grupos mudaram desde a pré-visualização; nada foi aplicado. Execute a distribuição novamente.';
$string['errortaskpending'] = 'Já existe uma distribuição sendo aplicada neste curso. Aguarde a conclusão antes de iniciar outra.';
$string['event_distribution_applied'] = 'Distribuição aplicada';
$string['fieldcategory'] = 'Distribuição';
$string['fieldlocation'] = 'Local';
$string['fieldseats'] = 'Vagas';
$string['filternoseats'] = 'Apenas grupos sem "{$a}"';
$string['groupdist:distribute'] = 'Distribuir participantes nos grupos do curso';
$string['groupnewmembers'] = '{$a} novos membros';
$string['idnumbercolumn'] = 'ID';
$string['ignoregrouped'] = 'Ignorar usuários já nos grupos selecionados';
$string['ignoregrouped_help'] = 'Quando habilitado, participantes que já pertencem a algum dos grupos selecionados mantêm seu lugar e não são redistribuídos. Quando desabilitado, eles participam da alocação e podem ser adicionados a outros grupos.';
$string['legenddirty'] = 'alteração não salva';
$string['legendnoseats'] = '"{$a}" não definido';
$string['legendover'] = 'membros acima de "{$a}"';
$string['loadmoregroups'] = 'Mostrar mais grupos';
$string['massactions'] = 'Ações em massa';
$string['massapplyhint'] = 'Preenche a tabela; nada é gravado até salvar.';
$string['massapplyseats'] = 'Definir "{$a}" de todos os grupos para';
$string['memberscolumn'] = 'Membros';
$string['memberscolumnunit'] = 'membros';
$string['membersnotshown'] = '+ {$a} participantes não exibidos na amostra';
$string['messageprovider:applyresult'] = 'Resultado de uma distribuição de participantes em segundo plano';
$string['noseatsnote'] = '{$a->noseats} dos {$a->total} grupos selecionados não têm valor em "{$a->field}" — serão tratados como sem limite.';
$string['nothingtoapply'] = 'A distribuição não adicionaria nenhum membro; nada foi aplicado.';
$string['overbook'] = 'Overbooking por grupo';
$string['overbook_help'] = 'Participantes extras permitidos além das vagas declaradas, por grupo, quando as vagas não bastam para todos. 0 desativa o overbooking.';
$string['pluginname'] = 'Distribuição de grupos';
$string['previewcapped'] = 'Pré-visualização limitada a {$a->cap} de {$a->total} grupos. A aplicação da distribuição cobre todos os grupos normalmente.';
$string['previewdistribution'] = 'Pré-visualizar distribuição';
$string['previewnothingsaved'] = 'Pré-visualização da distribuição em {$a} grupos selecionados. Nada foi gravado ainda — as participações só são escritas ao aplicar.';
$string['previewstale'] = 'Inscrições ou grupos mudaram durante a pré-visualização. Recarregue a pré-visualização antes de aplicar.';
$string['privacy:metadata:preference:bulkeditcols'] = 'Colunas que o usuário recolheu na tabela de edição de grupos em lote.';
$string['recapaffinity'] = 'Campo: {$a}';
$string['recapcohort'] = 'Coorte: {$a}';
$string['recapoverbook'] = 'Overbooking: até +{$a} por grupo';
$string['recaprole'] = 'Papel: {$a}';
$string['samplesheading'] = 'Amostra da distribuição — até 5 participantes por grupo';
$string['savedchanges'] = '{$a} alterações salvas.';
$string['savingprogress'] = 'Salvando… ({$a->done}/{$a->total})';
$string['seatsignored'] = 'Vagas não consideradas';
$string['seatssection'] = 'Vagas e overbooking';
$string['selectedgroups'] = 'Grupos selecionados ({$a})';
$string['selectedgroupsnote'] = 'A seleção vem da página de grupos. Para mudá-la, volte e selecione outros grupos.';
$string['showinggroups'] = 'Exibindo {$a->shown} de {$a->total} grupos';
$string['stataverage'] = 'média de novos membros por grupo';
$string['statcandidates'] = 'participantes a distribuir';
$string['statgroups'] = 'grupos selecionados';
$string['statseats'] = 'vagas declaradas + overbooking usado';
$string['statusnew'] = 'Novo';
$string['statwarnings'] = 'avisos';
$string['task_apply_distribution'] = 'Aplicar distribuição de participantes';
$string['unsavedchanges'] = '{$a} grupo(s) com alterações não salvas';
$string['useseats'] = 'Respeitar o campo "{$a}" dos grupos';
$string['useseats_help'] = 'Cada grupo recebe no máximo o número de participantes declarado no seu campo personalizado "{$a}" (descontando membros atuais, somando o overbooking). Grupos sem valor são tratados como sem limite.';
$string['warningapart'] = '"{$a->value}" tem mais participantes do que grupos disponíveis; {$a->count} alocações repetem o valor dentro de um grupo.';
$string['warningcommslow'] = 'O subsistema de comunicação está ativo: cada participação dispara uma sincronização de sala, então a aplicação pode demorar bem mais.';
$string['warningnoseats'] = '{$a->count} grupos selecionados não declaram valor em "{$a->field}" e são tratados como sem limite.';
$string['warningnovalue'] = '{$a->count} participantes não têm valor em "{$a->field}" e foram alocados sem a regra de afinidade.';
$string['warningsplit'] = 'Participantes com "{$a->value}" não couberam em um único grupo e foram divididos entre {$a->count} grupos.';
$string['warningunassigned'] = '{$a} participantes não puderam ser alocados — todos os grupos estão na capacidade máxima. Aumente as vagas ou o overbooking.';
