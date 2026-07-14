{* +--------------------------------------------------------------------+
 | Bevestigings-popup "Voorheen wachtlijst"                             |
 | Zelfde opbouw als de "Criteria prima"-popup (gedeeld detailblok) +   |
 | eigen actie-blok. Zet na bevestiging PART_DEEL_INTERN.wachtlijst_eraf |
 | op vandaag; de motor (Regel D) zet de status door.                   |
 +--------------------------------------------------------------------+ *}

<div class="crm-block crm-form-block crm-partstatus-wachtlijsteraf-form-block">

	{* HEADER *}
	<div class="help">
		{ts}Controleer de gegevens en bevestig om deze registratie van de wachtlijst te halen.{/ts}
	</div>

	{* KERNGEGEVENS VAN DE REGISTRATIE *}
	<table class="form-layout-compressed">
		<tr>
			<td class="label">{ts}Naam{/ts}</td>
			<td colspan="2"><strong>{$displayname}</strong></td>
		</tr>
		<tr>
			<td class="label">{ts}Event{/ts}</td>
			<td colspan="2">{$event_title}</td>
		</tr>
		<tr>
			<td class="label">{ts}Datum registratie{/ts}</td>
			<td colspan="2">{$register_date|crmDate}</td>
		</tr>
		<tr>
			<td class="label">{ts}Op wachtlijst sinds{/ts}</td>
			<td colspan="2">{if $wachtlijst_erop}{$wachtlijst_erop|crmDate}{else}{ts}onbekend{/ts}{/if}</td>
		</tr>
		<tr>
			<td class="label">{ts}Keren deelnemer{/ts}</td>
			<td colspan="2">{$curcv_keer_deel}</td>
		</tr>
	</table>

	{* GEGEVEN NAAST INDICATIE *}
	<table class="form-layout-compressed">
		<tr>
			<th></th>
			<th>{ts}Gegeven{/ts}</th>
			<th>{ts}Indicatie{/ts}</th>
		</tr>
		<tr>
			<td class="label">{ts}Leeftijd op kamp{/ts}</td>
			<td>{if $leeftijd_rondjaren}{$leeftijd_rondjaren} {ts}jaar{/ts}{if $leeftijd_rondmaand}, {$leeftijd_rondmaand} {ts}mnd{/ts}{/if}{else}{ts}onbekend{/ts}{/if}</td>
			<td>{if $criteria_leeftijd_label}{$criteria_leeftijd_label}{else}{ts}onbekend{/ts}{/if}</td>
		</tr>
		<tr>
			<td class="label">{ts}School/klas{/ts}</td>
			<td>{if $groepklas_label}{$groepklas_label}{else}{ts}onbekend{/ts}{/if}</td>
			<td>{if $criteria_school_label}{$criteria_school_label}{else}{ts}onbekend{/ts}{/if}</td>
		</tr>
	</table>

	{* WAT GAAT ER GEBEUREN *}
	<div class="messages status no-popup">
		<div class="icon inform-icon"></div>
		{ts}Bij bevestigen wordt de einddatum wachtlijst op vandaag gezet en gaat de status door naar <strong>Voorheen wachtlijst</strong>.{/ts}
	</div>

	{* FOOTER MET KNOPPEN *}
	<div class="crm-submit-buttons">
		{include file="CRM/common/formButtons.tpl" location="bottom"}
	</div>

</div>
