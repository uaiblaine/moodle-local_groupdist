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

$string['addrule'] = 'Adicionar regra';
$string['affinitynone'] = 'Não agrupar (padrão)';
$string['affinitysection'] = 'Afinidade';
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
$string['auditby'] = 'Aplicada por';
$string['auditgroupdeleted'] = 'grupo excluído depois';
$string['auditlog'] = 'Log de distribuições';
$string['auditmaskedvalue'] = 'valor oculto para você';
$string['auditmeta'] = 'seed {$a->seed} · plugin {$a->version} · {$a->written} de {$a->total} participações gravadas';
$string['auditnogroup'] = 'Sem grupo';
$string['auditnoruns'] = 'Nenhuma distribuição foi aplicada neste curso ainda.';
$string['auditoutcomefailed'] = 'falhou';
$string['auditoutcomeplanned'] = 'planejada';
$string['auditoutcomeskipped'] = 'sem escrita necessária';
$string['auditoutcomeunassigned'] = 'sem vaga';
$string['auditoutcomewritten'] = 'gravada';
$string['auditremoved'] = 'Participante removido';
$string['auditrestored'] = 'Restaurado de backup';
$string['auditretentiondays'] = 'Retenção do log de auditoria (dias)';
$string['auditretentiondays_desc'] = 'Execuções de distribuição mais antigas do que isso são apagadas por uma tarefa agendada diária. 0 mantém o log para sempre.';
$string['auditrules'] = 'Regras aplicadas (rótulos da época)';
$string['auditrun'] = 'Execução de {$a}';
$string['auditsnapshotnote'] = 'Fotografia: tudo abaixo mostra os dados como estavam no momento da aplicação. Mudanças posteriores em perfis, coortes ou grupos não alteram este registro.';
$string['auditstatus'] = 'Status';
$string['auditstatusaborted'] = 'Abortada';
$string['auditstatuscompleted'] = 'Concluída';
$string['auditstatuspartial'] = 'Parcial';
$string['auditstatuspending'] = 'Pendente';
$string['auditwhen'] = 'Quando';
$string['auditwhy'] = 'Por que aqui?';
$string['auditwhyapart'] = 'Regra {$a->index} · Manter separados · {$a->label}: separado(a) de {$a->others}.';
$string['auditwhyapartsame'] = 'Regra {$a->index} · Manter separados · {$a->label}: divide o grupo com {$a->others}.';
$string['auditwhymasked'] = 'Regra {$a->index} · {$a->label}: valor oculto para você.';
$string['auditwhynone'] = 'Nenhuma regra restringiu este participante.';
$string['auditwhytogether'] = 'Regra {$a->index} · Manter juntos · {$a->label}: valor "{$a->value}" — alocado(a) com mais {$a->count} do mesmo valor.';
$string['auditwritten'] = 'Gravadas';
$string['backadjust'] = 'Voltar e ajustar';
$string['bulkeditfootnote'] = 'Somente os campos personalizados são gravados aqui. Nome, descrição e demais configurações abrem em "Editar".';
$string['bulkeditgroups'] = 'Editar grupos em lote';
$string['chosenoptions'] = 'Opções escolhidas';
$string['cleanupfieldsonuninstall'] = 'Remover campos de grupo ao desinstalar';
$string['cleanupfieldsonuninstall_desc'] = 'Se habilitado, desinstalar o plugin exclui os campos personalizados de grupo provisionados por ele (vagas e local) junto com os valores armazenados em cada grupo. Se desabilitado, os campos e seus dados são mantidos e podem ser gerenciados nos campos personalizados de grupo.';
$string['cohortsourcelabel'] = 'Coorte: {$a}';
$string['columnsbutton'] = 'Colunas';
$string['columnsmenuhead'] = 'Grupo e "{$a}" ficam sempre visíveis. Outros campos personalizados de grupo entram nesta lista automaticamente.';
$string['distributeparticipants'] = 'Distribuir participantes';
$string['editgroupsettings'] = 'Configurações do grupo — {$a}';
$string['enrolstartbadge'] = 'início {$a}';
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
$string['groupdist:viewauditlog'] = 'Ver o log de auditoria de distribuições';
$string['groupnewmembers'] = '{$a} novos membros';
$string['idnumbercolumn'] = 'ID';
$string['ignoregrouped'] = 'Ignorar usuários já nos grupos selecionados';
$string['ignoregrouped_help'] = 'Quando habilitado, participantes que já pertencem a algum dos grupos selecionados mantêm seu lugar e não são redistribuídos. Quando desabilitado, eles participam da alocação e podem ser adicionados a outros grupos.';
$string['includefutureenrol'] = 'Incluir inscrições com início futuro';
$string['includefutureenrol_help'] = 'Inscrições ativas cuja data de início ainda está no futuro entram na distribuição — diferente de inscrições suspensas ou expiradas, que sempre ficam de fora. Só tem efeito com "Incluir apenas inscrições ativas" marcado, e apenas para quem pode ver participantes não ativos.';
$string['legenddirty'] = 'alteração não salva';
$string['legendnoseats'] = '"{$a}" não definido';
$string['legendover'] = 'membros acima de "{$a}"';
$string['loadmoregroups'] = 'Mostrar mais grupos';
$string['massactions'] = 'Ações em massa';
$string['massapplyhint'] = 'Preenche a tabela; nada é gravado até salvar.';
$string['massapplyseats'] = 'Definir "{$a}" de todos os grupos para';
$string['maxaffinityrules'] = 'Máximo de regras de afinidade';
$string['maxaffinityrules_desc'] = 'Limite superior de regras de afinidade aceitas por distribuição. Um guarda-corpo de validação — o custo da alocação cresce de forma aproximadamente linear com o número de regras.';
$string['memberscolumn'] = 'Membros';
$string['memberscolumnunit'] = 'membros';
$string['membersnotshown'] = '+ {$a} participantes não exibidos na amostra';
$string['messageprovider:applyresult'] = 'Resultado de uma distribuição de participantes em segundo plano';
$string['modeapart'] = 'Manter separados';
$string['modetogether'] = 'Manter juntos';
$string['noseatsnote'] = '{$a->noseats} dos {$a->total} grupos selecionados não têm valor em "{$a->field}" — serão tratados como sem limite.';
$string['nothingtoapply'] = 'A distribuição não adicionaria nenhum membro; nada foi aplicado.';
$string['overbook'] = 'Overbooking por grupo';
$string['overbook_help'] = 'Participantes extras permitidos além das vagas declaradas, por grupo, quando as vagas não bastam para todos. 0 desativa o overbooking.';
$string['pluginname'] = 'Distribuição de grupos';
$string['previewcapped'] = 'Pré-visualização limitada a {$a->cap} de {$a->total} grupos. A aplicação da distribuição cobre todos os grupos normalmente.';
$string['previewdistribution'] = 'Pré-visualizar distribuição';
$string['previewnothingsaved'] = 'Pré-visualização da distribuição em {$a} grupos selecionados. Nada foi gravado ainda — as participações só são escritas ao aplicar.';
$string['previewstale'] = 'Inscrições ou grupos mudaram durante a pré-visualização. Recarregue a pré-visualização antes de aplicar.';
$string['privacy:metadata:local_groupdist_run'] = 'Uma fotografia por execução de distribuição aplicada: quem executou e os insumos de que o plano foi função.';
$string['privacy:metadata:local_groupdist_run:courseid'] = 'O curso em que a distribuição rodou.';
$string['privacy:metadata:local_groupdist_run:optionsjson'] = 'As opções da distribuição como escolhidas no momento da aplicação.';
$string['privacy:metadata:local_groupdist_run:rulesjson'] = 'As regras de afinidade, com os rótulos das fontes resolvidos no momento da aplicação.';
$string['privacy:metadata:local_groupdist_run:status'] = 'O desfecho da execução (concluída, parcial ou abortada).';
$string['privacy:metadata:local_groupdist_run:timecreated'] = 'Quando a execução foi aplicada.';
$string['privacy:metadata:local_groupdist_run:userid'] = 'O usuário que aplicou a distribuição (0 após pseudonimização).';
$string['privacy:metadata:local_groupdist_run_user'] = 'Fotografia por participante de uma execução: os valores das regras no momento da aplicação, o grupo planejado e o desfecho da escrita.';
$string['privacy:metadata:local_groupdist_run_user:groupid'] = 'O grupo em que o participante foi planejado.';
$string['privacy:metadata:local_groupdist_run_user:userid'] = 'O participante (0 após pseudonimização).';
$string['privacy:metadata:local_groupdist_run_user:valuesjson'] = 'O valor do participante em cada regra no momento da aplicação (valores de campos de perfil, pertencimento a coortes; apagado na pseudonimização).';
$string['privacy:metadata:local_groupdist_run_user:writestatus'] = 'Se a participação foi gravada, falhou, era desnecessária ou não encontrou vaga.';
$string['privacy:metadata:preference:bulkeditcols'] = 'Colunas que o usuário recolheu na tabela de edição de grupos em lote.';
$string['recapcohort'] = 'Coorte: {$a}';
$string['recapoverbook'] = 'Overbooking: até +{$a} por grupo';
$string['recaprole'] = 'Papel: {$a}';
$string['recaprule'] = '{$a->index} · {$a->mode}: {$a->label}';
$string['rulechoosecohort'] = 'Escolha a coorte…';
$string['rulechoosefield'] = 'Escolha o campo…';
$string['rulecohortlabel'] = 'Coorte da regra {$a}';
$string['rulecohortsearchlabel'] = 'Pesquisa de coorte da regra {$a}';
$string['ruleconnector'] = 'e também';
$string['ruledelete'] = 'Remover regra {$a}';
$string['rulefieldlabel'] = 'Campo da regra {$a}';
$string['rulemodelabel'] = 'Estratégia da regra {$a}';
$string['rulemovedown'] = 'Descer prioridade da regra {$a}';
$string['rulemoveup'] = 'Subir prioridade da regra {$a}';
$string['rulereportheading'] = 'Relatório por regra';
$string['rulereportmore'] = '… e mais {$a} valores';
$string['rulereportnovalue'] = '{$a} participantes sem valor';
$string['rulereportsplit'] = 'dividido entre {$a} grupos';
$string['rulereportviolations'] = '{$a} repetições dentro de grupo';
$string['rulesearchnoresults'] = 'Nenhuma coorte encontrada';
$string['rulesprioritynote'] = 'Todas as regras se aplicam ao mesmo tempo (E). A ordem define a prioridade: quando regras entram em conflito, ou quando um agrupamento precisa ser dividido por falta de vagas, a regra mais acima prevalece e a violação é contada na regra mais abaixo — sempre com aviso na pré-visualização.';
$string['rulestatusrepeats'] = '{$a} repetições';
$string['rulestatusvalues'] = '{$a} valores';
$string['ruletypecohort'] = 'Coorte';
$string['ruletypefield'] = 'Campo de perfil';
$string['ruletypelabel'] = 'Tipo da regra {$a}';
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
$string['task_cleanup_audit'] = 'Limpar execuções expiradas do log de auditoria';
$string['unsavedchanges'] = '{$a} grupo(s) com alterações não salvas';
$string['useseats'] = 'Respeitar o campo "{$a}" dos grupos';
$string['useseats_help'] = 'Cada grupo recebe no máximo o número de participantes declarado no seu campo personalizado "{$a}" (descontando membros atuais, somando o overbooking). Grupos sem valor são tratados como sem limite.';
$string['warningapart'] = '"{$a->value}" ({$a->field}) tem mais participantes do que grupos disponíveis; {$a->count} alocações repetem o valor dentro de um grupo.';
$string['warningcommslow'] = 'O subsistema de comunicação está ativo: cada participação dispara uma sincronização de sala, então a aplicação pode demorar bem mais.';
$string['warningcontradiction'] = 'Regras de manter juntos e manter separados atingiram os mesmos participantes; a regra mais prioritária, sobre "{$a->field}", prevaleceu em {$a->count} caso(s).';
$string['warningnoseats'] = '{$a->count} grupos selecionados não declaram valor em "{$a->field}" e são tratados como sem limite.';
$string['warningnovalue'] = '{$a->count} participantes não têm valor em "{$a->field}" e foram alocados sem a regra de afinidade.';
$string['warningsplit'] = 'Participantes com "{$a->value}" não couberam em um único grupo e foram divididos entre {$a->count} grupos.';
$string['warningunassigned'] = '{$a} participantes não puderam ser alocados — todos os grupos estão na capacidade máxima. Aumente as vagas ou o overbooking.';
