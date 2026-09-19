<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Identificar equipamentos que estão há muito tempo sem enviar posição. A tela abre com cinco faixas — <strong>menos de 24 horas</strong>, <strong>mais de 1 dia</strong>, <strong>mais de 7 dias</strong>, <strong>mais de 30 dias</strong> e <strong>nunca posicionados</strong> — com a quantidade de equipamentos em cada uma e uma barra mostrando a proporção.</p>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Clicar em uma faixa</td><td>Abre a lista dos equipamentos daquela faixa: IMEI, nome, modelo, cliente, última posição e há quantas horas</td></tr>
<tr><td>Ordenar por Última Posição</td><td>Setinha no cabeçalho. Em ordem crescente, os mais desatualizados vêm primeiro — os "nunca posicionados" encabeçam a lista</td></tr>
<tr><td>← Voltar</td><td>Fecha a lista e devolve o resumo com as cinco faixas</td></tr>
<tr><td>Ver posições no mapa</td><td>Abre um mapa com os equipamentos da consulta; cada marcador traz a placa e a data/hora da última posição conhecida</td></tr>
<tr><td>Exportar</td><td>Baixa Excel ou PDF da <strong>frota completa</strong> — a lista inteira ordenada por tempo sem transmitir, que é a que se leva para a reunião. A grade principal traz placa, há quanto tempo sem transmitir, data/hora, endereço, mapa, ignição e status do GPS</td></tr>
</table>

<div class="callout info">
<strong>O arquivo exportado é sempre da frota inteira</strong>, mesmo que você tenha aberto uma faixa antes de clicar em Exportar. Isso é proposital: a faixa serve para investigar na tela, e o arquivo serve para levar o quadro completo.
</div>
