# Análise — Whitelabel Tracking (`11792.whitelabeltracking.com`)

> Levantamento de produto feito em 20/08/2026 com credenciais de trial
> (`flaviohses@live.com`, reseller "TCK", `ResellerId 11792`, `ClientId 68462`).
> Objetivo: mapear telas, dinâmica, padrões UI/UX e regras de negócio da
> plataforma, e fazer o paralelo com o **jimi_webhook** (YUV Parity) para
> identificar funcionalidades que agreguem valor.

---

## 1. O que é a plataforma

SPA **Next.js/React + MUI**, com dados numa API separada (`api.tracking-data.net`,
auth `Bearer JWT` + cookie `app_cookie_2`). Plataforma de rastreamento
**multi-tenant com white-label**, vendida a *resellers* (trial de 13 dias para
"TCK"). Identidade no JWT: `pkUserId`, `user_type`, `roleId`, `is_primary_owner`,
`is_billing`, `is_technician`, `lang`.

### Rotas (extraídas do `_buildManifest.js`)

`/` · `/dashboard` · `/livelocation` · `/trips` · `/alerts` · `/videos` ·
`/zones` · `/reports` · `/control-room` · `/crashesforensics` · `/asset-info` ·
`/installation` · `/modules` · `/ntsa` · `/share-map` · `/admin` ·
`/menu/[id]/[slug]`.

---

## 2. Mapa de funcionalidades (por módulo)

| Módulo | Endpoint-chave (API) | O que faz |
|---|---|---|
| **Dashboard** | `/Dashboard/GetWidgets`, `SaveDashboard`, `UpdateWidgetType`, `SaveDashboardWidgetsLayout` | Widgets arrastáveis com layout por cliente. Catálogo: `Alerts`, `MaintenanceOverview`, `TopSpeed`, `Top5DriverEcoPerformance`, `Top5VehicleOverSpeed`, `Top5VehicleOdometer`, `OdometerGraph`, `FleetRefuel`, `FuelConsumptionStats`, `MostVisitedLocation`, `ReminderCountSummary`, `PercentageOfFleetMoving`, `TotalViolations`, `Notifications`, `NewTasksAndReminders`, `OverviewDriverRankings` |
| **Visão geral de hoje** | `/Home/ShowTodaysOverview` | `Alerts`, `Violations`, `IdleTime`, `totalDistanceForDay`, `TotalMovingTime`, `TotalParkingTime`, `ParkTimeAfterFirstTrip`, `Overspeed`, `TripStartedHash` |
| **Live Location** | `/LiveLocation/InitializeLiveLoc`, `GetDeviceLiveData`, `CheckIfPasswordProtected`, `ValidatePassword` | Monitoramento ao vivo + **compartilhamento público com senha** |
| **Control Room** | `/Home/FetchClients`, `GetClientGroups` | Console de monitoramento por grupo de clientes |
| **Trips** | `/Trips/ConfigureClientTripTypeClassification`, `GetTripsAdditionalSummary`, `GetAssetTripTypeClassification` | Replay com **timeline scrub**, resumo, distância 24 h; **classificação de tipo de viagem** (business/pessoal, `use_trip_start`) |
| **Alerts** | `/Alerts/GetEditableAlertDataToPopup`, `CreateCustomAlert`/`EditAlert`/`DeleteAlert`/`DuplicateAlert` | Alertas **editáveis**, alertas **customizados**, preferência por tipo (`bOnScreenNotification`/`bPushNotification`), "My Alerts" vs "All Alerts" |
| **Zones** | `/Home/GetGeoZoneList`, `GetZonesActivity`, `GetZonesAssets` | Geocercas (círculo e polígono), velocidade máx. por zona, `InZoneTime`/`timeSpentInZone` |
| **Crashes Forensics** | `/CrashesForensics/GetIncidentDetails`, `GetCrashTelemetry`, `ShowDriverScore`, `GetDaysViolationPriorToIncident`, `GetOtherPartyInvolved`, `GetCrashComments` | **Reconstrução de acidente**: velocidade estrada×veículo, `TotalHarshAcceleration`, `TotalOverDeviceSpeed/PlatformSpeed/RoadSpeed`, terceiro envolvido, comentários |
| **EcoDriving** | `/EcoDrivingConfig/GetScoreCategoryScheme`, `GetMultipleAssetsScore`, `SaveEcoDrivingConfig` | **Score de direção** com faixas de risco coloridas |
| **Maintenance** | `/Maintenance/CreateMaintenance`, `GetAssetMaintenance`, `UpdateMaintenanceAsComplete`, `FetchMaintenanceClosestValue` | Manutenção preventiva por odômetro/horas + tarefas/lembretes |
| **Fuel Module** | `/FuelModule/GetSensorData`, `UpdateSensorData`, `GetConnectedAnalogDevice` | Sensores de combustível (nível, L/100km, reabastecimento, gráfico) |
| **Asset Info** | `/Home/GetCurrentTripStats`, `GetFuelGraph`, `ShowIOCurrentStatus`, `ShowInZones`, `/Assetinfo/GetTPMSSensorsData` | Detalhe do ativo: trip atual, combustível, status de I/O, zonas, **TPMS** |
| **NTSA** | `/NTSA/GetAllAssetsTelemetry`, `ForwardToNtsa`, `ExportViolationHistoryReport` | Compliance governamental (Kenya) |
| **Admin** | `/Admin/GetRecursiveTree`, `GetResellerDeviceList`, `SendGPRSCommand`, `/EndpointManagement/*` | Árvore de clientes, comandos GPRS, roles/permissões, API keys por reseller |

---

## 3. Padrões UI/UX

- **Dashboard widgetizado** com drag-and-drop e dimensões `minW/minH`.
- **Ícone do veículo colorido por estado** (`UserIconConfig`): `Ignition_On_Speed_Above_Zero`, `Ignition_On_Speed_Is_Zero`, `Ignition_Off`, `Overspeed`, `device_Offline` — cada um com cor própria.
- **Multi-map**: Google / OSM / Mapbox / Skobbler / mapa WLT custom + Nominatim geocode.
- **Unidades configuráveis** por grandeza (volume/temperatura/elétrica) com `DataType` e `DecimalPlaces`.
- **White-label completo**: `primaryColor`/`accentColor` por reseller, logo, foto-padrão por tipo de ativo, menus customizados por URL.
- **Fuso/idioma por usuário**: `TimeZoneID`, `UserTimeOffset`, `LangugageCode`.

### Score EcoDriving (regra de negócio)

| Faixa | Range | Cor |
|---|---|---|
| Low Risk | 95–100 | `#D4E6B5` |
| Mild Risk | 75–94 | `#D8D800` |
| Mid Risk | 60–74 | `#FF9226` |
| High Risk | 0–59 | `#C51026` |

---

## 4. Paralelo com o jimi_webhook

### O que NÓS temos e o WLT não tem
- Gateway de webhook **Jimi** (GPS/heartbeat/alarm/event).
- **MDVR / telemetria de vídeo** (ao vivo / playback / downloads).
- **Motor de ocorrências DMS** (alarme → ocorrência → tratativa → risco, por cliente).
- **Catálogo profundo de comandos** por protocolo (JTT/JIMI), inclusive família ADAS.
- **Gestão de firmware por modelo** (v4.9.32).

### O que o WLT tem e falta no nosso — por valor estratégico

**Alto valor**
1. **Score de direção / EcoDriving** — faixas de risco coloridas por motorista. Complementa o motor de ocorrências (ocorrência → score → risco).
2. **Crashes Forensics** — reconstrução de acidente. Natural para nós: já temos o alarme de colisão/capotamento; falta a "linha do tempo forense" ao redor do evento.
3. **Manutenção preventiva por odômetro/horas** — lembretes/tarefas sobre `trips`/`device_state_segments` que já construímos. ✅ *Entra nesta rodada (v4.10).*
4. **Alertas editáveis + notificação por tipo + alertas customizados** — já temos `notification_rules`; falta toggle por tipo na mão do usuário final e anotação/edição do alerta.
5. **Ícone colorido por estado no mapa** — barato e de grande impacto no `/rastreamento`. ✅ *Entra nesta rodada (v4.10).*

**Médio valor**
6. **Trip replay com timeline scrub** — upgrade de UX do mapa de rota. ✅ *Entra nesta rodada (v4.10).*
7. **Dashboard widgetizado por cliente** — substituir KPIs fixos por widgets configuráveis. ✅ *Entra nesta rodada (v4.10, adaptado para layout por usuário).*
8. **Compartilhamento público de localização com senha** (share-map).
9. **Fuel / TPMS / temperatura** — só se houver hardware compatível na frota.

**Baixo / contextual**
10. NTSA (Kenya) — não se aplica ao BR; white-label por reseller — só se virarmos plataforma.

---

## 5. Conclusão

A plataforma é **mais madura em gestão de frota horizontal** (score, manutenção,
combustível, crash forensics, widgets), mas **não tem** o diferencial do
jimi_webhook: vídeo-telemetria MDVR e o motor de ocorrências DMS. Os quatro
itens priorizados nesta rodada (3, 5, 6, 7) fecham lacunas de frota que
complementam — e não concorrem com — o núcleo DMS/vídeo do nosso produto.

Implementação: ver `PLANO_IMPLEMENTACAO_v4.10.md`.
