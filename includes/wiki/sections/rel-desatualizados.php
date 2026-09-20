<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Identificar equipamentos que estão há muito tempo sem se comunicar com a bycamera. A tela abre com <strong>dois grupos</strong> — <strong>Desatualizados</strong> e <strong>Em dia</strong> — com a quantidade de equipamentos em cada um, a porcentagem do total e uma barra mostrando a proporção. Só entram equipamentos <strong>ativos</strong>: o que foi desativado não conta.</p>

<div class="callout info">
<strong>O que faz um equipamento ficar "Desatualizado":</strong> o critério é o <strong>último sinal recebido dele</strong> — de qualquer tipo (posição, sinal de vida ou evento), o que vier por último —, e não apenas a posição de GPS. A tolerância depende da ignição: <strong>5 minutos</strong> sem sinal com a ignição ligada, <strong>30 minutos</strong> com a ignição desligada (ou sem leitura de ignição). Passou disso, está desatualizado. Um equipamento que fala com a bycamera mas está sem sinal de satélite <strong>não</strong> é desatualizado: ele continua "em dia" e aparece com "Sem sinal" na coluna Posição GPS.
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Clicar em <strong>Desatualizados</strong></td><td>Abre a lista dos equipamentos desse grupo: IMEI, nome, modelo, cliente, ignição e há quanto tempo estão sem comunicar. O grupo <strong>Em dia</strong> não abre lista própria — ele aparece na frota completa, mais abaixo</td></tr>
<tr><td>Ordenar por Sem comunicar há</td><td>Setinha no cabeçalho. Em ordem crescente, os mais desatualizados vêm primeiro — quem <strong>nunca comunicou</strong> encabeça a lista</td></tr>
<tr><td>← Voltar</td><td>Fecha a lista e devolve o resumo com os dois grupos</td></tr>
<tr><td>Ver no Mapa</td><td>Abre um mapa com os equipamentos da página da frota completa que está na tela; cada marcador traz a placa e a data/hora da última posição conhecida. Equipamento sem coordenada não aparece no mapa</td></tr>
<tr><td>Exportar</td><td>Baixa Excel ou PDF da <strong>frota completa</strong> — todos os equipamentos, do mais tempo sem comunicar para o menos, que é a lista que se leva para a reunião. Traz placa, status (Desatualizado ou Em dia), há quanto tempo sem comunicar, data/hora, endereço, mapa, ignição e status do GPS</td></tr>
</table>

<p>A grade <strong>Frota completa</strong> mostra, para cada equipamento: a placa, o <strong>Status</strong> (Desatualizado ou Em dia), há quanto tempo está sem comunicar, a data/hora da última posição, o endereço, o link do mapa, a ignição e a <strong>Posição GPS</strong> — "Válido" quando houve posição nos últimos 30 minutos, "Sem sinal" quando não. Essa última coluna é um sinal à parte do Status: o equipamento pode estar em dia e mesmo assim sem posição.</p>

<div class="callout info">
<strong>O arquivo exportado é sempre da frota inteira</strong>, mesmo que você tenha aberto a lista de Desatualizados antes de clicar em Exportar no topo da tela. Isso é proposital: a lista serve para investigar na tela, e o arquivo serve para levar o quadro completo. Com a lista de Desatualizados aberta, ela tem os botões de exportação próprios, que baixam só os desatualizados.
</div>
