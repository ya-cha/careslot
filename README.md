# careslot · Kita-Notbetreuung

Laravel 13, PHP 8.5, Livewire 4, Blade und Tailwind CSS 4. Deutschsprachige Verwaltung mit persönlichen Elternlinks, zweiphasiger Vergabe und protokollierter Bestätigung. Keine öffentliche Registrierung, keine Elternkonten und kein SPA.

## Installation

Voraussetzungen: PHP 8.5 mit PDO-PgSQL oder PDO-MySQL, Composer, Node.js passend zur installierten Vite-Version, PostgreSQL oder MySQL. Die vorhandene SQLite-Konfiguration wird für lokale Entwicklung und isolierte Tests unterstützt; konkurrierende Bestätigungen im Betrieb benötigen PostgreSQL/MySQL mit Zeilensperren.

```sh
composer install
cp .env.example .env
php artisan key:generate --no-interaction
npm ci
npm run build
```

In `.env` Datenbankzugang, `APP_URL`, `APP_LOCALE=de`, SMTP und Absender konfigurieren. PostgreSQL ist in `.env.example` vorbelegt. Für MySQL `DB_CONNECTION=mysql` und den passenden Port setzen. Datenbank vorher anlegen. Die Anwendungszeitzone ist `Europe/Berlin`; Kalenderwochen werden nach ISO 8601 ausgewertet.

```sh
php artisan migrate --no-interaction
php artisan careslot:admin beirat@example.org --name="Elternbeirat"
```

Das Administratorpasswort wird verdeckt abgefragt und muss mindestens zwölf Zeichen haben. Es gibt keine eingebauten Standardzugangsdaten. `db:seed` erstellt kein Konto.

Lokal: `composer run dev` startet Webserver, Vite, Queue und Scheduler gemeinsam. Bei getrennten Prozessen zusätzlich zu `php artisan serve` und `npm run dev` sowohl den Queue-Worker als auch `php artisan schedule:work` starten. Anmeldung unter `/login`. Der Webserver muss `public/` als Document Root verwenden.

## Einrichtung und Ablauf

1. Unter **Kita-Jahre** ein Jahr anlegen.
2. Unter **Gruppen & Admins** Gruppen anlegen und Administratorkonten zuordnen. Unter **Kinder & Familien** jedes Kind einer Gruppe zuordnen und Kontakte aufnehmen. Für Montag bis Freitag Anspruchsart und Gültigkeit ausdrücklich konfigurieren. Bei Geschwistern oder einem weiteren Jahr eine vorhandene Familie derselben Gruppe auswählen. Mehrere Kontakte sind möglich; jeder erhält einen eigenen Link zur Rückmeldung des Kindes.
3. Erst nach Aufnahme der anfänglichen Kinder die **alphabetische Grundliste je Gruppe** erstellen. Sortiert wird nach Nachname, dann Vorname. Neue aktive Kinder werden anschließend angehängt. Ausgeschiedene Kinder bleiben historisch gespeichert und werden bei Vergaben übersprungen. Die angezeigten Positionen beziehen sich auf die vollständige Liste, daher sind Lücken bei ausgeblendeten ausgeschiedenen Kindern möglich.
4. Notbetreuungstag mit Kapazität, optionalem Zeitraum und optionaler Rückmeldefrist anlegen. Das Verfahren speichert die damals gültigen Anspruchsregeln für jedes aktive Kind.
5. Für jeden Wochentag genau eine gültige Anspruchsregel hinterlegen. Individuelle Betreuungsstunden werden nicht erfasst; der Anspruch gilt für den Betreuungstag.
6. **Anfragen versenden**. Eltern geben ihren regulären Bedarf oder Nachrückbedarf an und können ihre Antwort bis zum Fristende ändern. Mehrere Kontakte desselben Kindes bearbeiten dieselbe Rückmeldung; die letzte gespeicherte Antwort gilt. Berechtigte Gruppenadmins und die Gesamtverwaltung können in der Vergabeansicht bei einem Kind **Rückmeldung erfassen** beziehungsweise **Rückmeldung ändern** wählen und im Namen der Eltern speichern. Dafür ist kein persönlicher Elternlink erforderlich. Es gelten dieselben fachlichen Prüfungen wie im Elternformular. Admins können auch nach Fristende und nach dem Schließen der Rückmeldungsphase korrigieren, solange die Vergabe noch nicht verbindlich bestätigt wurde. Die Historie protokolliert das Administratorkonto und die bisherigen/neuen Werte. Eltern können die Antwort während der offenen Phase weiterhin selbst ändern. Ausgeschiedene oder in eine andere Gruppe gewechselte Kinder sowie verbindlich bestätigte Vergaben bleiben für diese Eingabe gesperrt. Eine Admin-Korrektur öffnet die Rückmeldungsphase nicht wieder für Eltern.
7. Während einer offenen Rückmeldungsphase kann die Frist in der Vergabeansicht unter **Rückmeldefrist ändern** angepasst oder durch Leeren des Felds entfernt werden. Neue Fristen müssen in der Zukunft liegen. Bestehende Antworten bleiben erhalten, Änderungen werden protokolliert. Noch ausstehende Anfrage-/Erinnerungs-E-Mails erhalten die neue Frist; bereits versendete Nachrichten werden nicht automatisch erneut versendet. Geschlossene Verfahren werden dadurch nicht wieder geöffnet. Rückmeldungen prüfen und Phase manuell schließen, auch vorzeitig bei vollständigen Antworten. Der Scheduler schließt Fristen automatisch. Bereits beim Erreichen der Frist werden weitere Elternänderungen verweigert.
8. Ändert ein Admin nach der Berechnung eine Rückmeldung oder erfasst erstmals eine Antwort, wird der aktuelle Vorschlag im Audit gesichert und verworfen. Danach muss der Vorschlag neu berechnet und erneut geprüft werden. Unverändertes erneutes Speichern verwirft keinen Vorschlag. Alte geöffnete Bestätigungsseiten können einen inzwischen geänderten Vorschlag nicht bestätigen. **Vorschlag berechnen** und beide Phasen sowie Listenbewegungen prüfen. Ohne Antwort bleibt der reguläre Anspruch bestehen. Für die Warteliste muss Bedarf ausdrücklich gemeldet werden.
9. Den vollständigen Vorschlag mit Checkbox **verbindlich bestätigen**. Erst dies speichert Ergebnisse, Wartelistenbewegungen und Versandaufträge. Wiederholte Bestätigungen haben keine zusätzlichen Auswirkungen.
10. Bei geänderter Personalsituation aus der abgeschlossenen Vergabe ein **neues Verfahren** mit Begründung und neuer Kapazität eröffnen. Es verweist auf den letzten Abschluss desselben Betreuungstages. Frühere Ergebnisse und Bewegungen bleiben bestehen.

## Fachliche und technische Entscheidungen

- Ein Kind erhält pro Verfahren höchstens einen Platz. Der Anspruch hängt von Wochentag, ISO-Kalenderwoche und Gültigkeit ab. Optionale Betreuungszeiten gelten für den gesamten Notbetreuungstag, nicht individuell je Kind.
- Benötigte reguläre Ansprüche oberhalb der Kapazität blockieren die Berechnung mit einer Meldung. Die Anwendung erfindet keine Kürzungs- oder Prioritätsregel für diesen fachlich nicht festgelegten Fall. Vor einer Vergabe muss die Situation geklärt werden.
- Phase 2 berücksichtigt die bestehende Reihenfolge. Nur tatsächlich zugeteilte und als benötigt gemeldete Nachrückplätze verschieben ein Kind ans Ende. Regulär freigegebene Plätze und bloße Anfragen verändern die Reihenfolge nicht. Nach regulären Ansprüchen und Warteliste übrige Plätze bleiben frei.
- Ein Verfahren besitzt eigene Rückmeldungen und Anspruchssnapshots. Änderungen der Stammdaten wirken auf neu eröffnete Verfahren. Vorschläge merken sich die Revision der Gruppenwarteliste im Kita-Jahr. Änderungen an Kindern oder der Warteliste nach der Berechnung erfordern Verwerfen und Neuberechnen.
- Alle konkurrierenden Änderungen von Kindern, Antworten und Wartelisten sowie Bestätigungen sperren zuerst das Kita-Jahr und anschließend das Verfahren. Positionen und Ergebnisse besitzen zusätzliche Unique Constraints. Eine Bestätigung ist eine Datenbanktransaktion; PostgreSQL/MySQL stellen dafür die Zeilensperren bereit.
- Endgültige Ergebnisse stehen in `allocation_results`. Der unverbindliche Vorschlag und seine Eingabedaten stehen in `emergency_allocations.proposal`. Die Berechnung speichert ausschließlich Vorschlags- und Auditdaten und verändert weder finale Ergebnisse noch Wartelistenpositionen.
- `waiting_list_movements` dokumentiert alle dadurch geänderten absoluten Positionen, einschließlich aufgerückter anderer Kinder. Nur die Phase-2-Gewinner werden tatsächlich ans Ende versetzt.

## E-Mail, Queue und Scheduler

SMTP konfigurieren: `MAIL_MAILER=smtp`, `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`. `MAIL_MAILER=log` schreibt lokal Nachrichten nach `storage/logs/laravel.log` (bei der Standardkonfiguration `LOG_CHANNEL=stack` und `LOG_STACK=single`) und versendet keine echten E-Mails. Auch für den Log-Mailer müssen Scheduler und Queue laufen: Der Scheduler übernimmt Versandaufträge aus der Outbox, die Queue verarbeitet sie anschließend. Nach dieser Verarbeitung wird der Auftrag als versendet markiert; beim Log-Mailer bedeutet das lediglich „protokolliert“. SMTP wurde ohne Zugangsdaten nicht gegen einen externen Dienst geprüft.

```sh
php artisan queue:work --tries=4 --timeout=45
php artisan schedule:work
```

Im Betrieb dauerhaft überwachten Queue-Worker verwenden und den Scheduler minütlich über `php artisan schedule:run` aufrufen. Bei Deployments `php artisan queue:restart` ausführen. Die Queue-Reservierungsdauer muss über dem Worker-Timeout liegen (Standard: 90 Sekunden).

Versandaufträge werden als transaktionale Outbox in `email_deliveries` gespeichert. Der Scheduler reiht offene Aufträge minütlich ein. Ein fehlender Worker oder ein SMTP-Fehler macht eine bestätigte Vergabe nicht rückgängig. Laravel Notifications werden innerhalb des Queue-Jobs versendet. Versandstatus, Versuchsanzahl und bereinigte Fehler sind in der Vergabeansicht sichtbar. Unique Jobs, Cache-Sperren, eindeutige Ergebnisaufträge und `sent_at` verhindern normale Doppelzustellung. Bei einem Prozessabbruch direkt nach SMTP-Annahme und vor Speicherung von `sent_at` ist eine erneute E-Mail technisch möglich; SMTP bietet keine transaktionale Exactly-once-Zusage.

```sh
# Offene Aufträge sofort einreihen
php artisan careslot:send-pending
# Nach Fehlerbehebung auch Aufträge mit vier erfolglosen Versuchen erneut einreihen
php artisan careslot:send-pending --retry
php artisan queue:failed
php artisan queue:retry all
```

Einzelne Nachrichten können in der Versandübersicht ausdrücklich erneut vorgemerkt werden. Widerrufene oder abgelaufene Links sperren auch noch ausstehende Versandaufträge. Änderungen einer Kontakt-E-Mail oder der Familienzuordnung widerrufen die betroffenen bisherigen Links. Erneute Anfragen erzeugen neue persönliche Links; alte bleiben bis zum Ablauf oder ausdrücklichen Widerruf gültig. Erinnerungen werden nur für fehlende Antworten vorgemerkt. Verbindliche Ergebnisaufträge werden pro Kind/Kontakt/Verfahren genau einmal erstellt.

## Elternlinks und Datenschutz

Tokens bestehen aus 32 kryptografisch zufälligen Bytes, werden als 64 Hex-Zeichen versendet und in `personal_access_tokens` ausschließlich als SHA-256-Hash gespeichert. Jeder Token ist an eine Rückmeldung und einen Elternkontakt gebunden und kann einzeln widerrufen werden. Ablauf: 30 Tage nach dem Betreuungstag. Ein Elternformular zeigt nur die Daten dieses Kindes, keine anderen Familien oder Wartelistenpositionen. Geschlossene Verfahren erlauben ausschließlich lesenden Zugriff.

Die Outbox muss den Link für den späteren Versand wiederherstellen können: Empfänger und Token werden deshalb zusammen als mit `APP_KEY` verschlüsselter Versandpayload gespeichert. Hashwerte/verschlüsselte Payloads werden bei Modellserialisierung ausgeblendet. `APP_KEY` sichern und vertraulich halten. Der Token selbst wird nicht in Audit-Einträge geschrieben. Die Elternseiten senden `Cache-Control: no-store`, `Referrer-Policy: no-referrer` und `X-Robots-Tag: noindex, nofollow`.

Im Betrieb HTTPS, `APP_DEBUG=false`, sichere Session-Cookies (`SESSION_SECURE_COOKIE=true`) und verschlüsselte Sessions (`SESSION_ENCRYPT=true`) konfigurieren. Webserver/Proxy-Zugriffsprotokolle dürfen persönliche `/r/…`-URLs nicht ungeschützt aufzeichnen. Datenbank, Backups und Mail-/Queue-Protokolle enthalten personenbezogene Daten und gehören in einen zugriffsbeschränkten Betrieb. Keine echten Elterndaten in Demo-/Testdatenbanken übernehmen. Aufbewahrung und Löschung mit dem Träger festlegen; historische Vergaben werden nicht automatisch gelöscht.

## Prüfung

```sh
php artisan test --compact tests/Feature/EmergencyAllocationTest.php
php artisan test --compact tests/Feature/CareAccessTest.php
php artisan test --compact
vendor/bin/pint --format agent
vendor/bin/phpstan analyse --memory-limit=1G
npm run build
```

Die Fachtests prüfen die fünf Wochentage, ISO-Parität einschließlich Jahreswechsel, tagesbezogene Ansprüche, fehlende Antworten, alle Vergabephasen, Wartelistenstabilität, Snapshots, Neuzugänge, ausgeschiedene Kinder, neue Verfahren und idempotente Bestätigung. HTTP-/Livewire-Tests prüfen Anmeldung, Rollen, fremde Kind-IDs, Tokenablauf/-widerruf, Fristen, Formulare, Versand und Fehlversuche. Tests nutzen eine isolierte SQLite-In-Memory-Datenbank. Echte parallele Bestätigungen und Sperrverhalten müssen zusätzlich im PostgreSQL-/MySQL-Zielsystem geprüft werden.

## Optionale Demodaten

Nur lokal und mit fiktiven Kontakten: `php artisan db:seed --class=DemoSeeder --no-interaction`. Dies erzeugt ein Demo-Jahr, sechs Kinder mit fünf Tagesregeln, eine Warteliste, ein offenes Verfahren mit Beispielantworten und einen unverbindlichen Vergabevorschlag für einen weiteren Tag. Die Regeln enthalten gerade/ungerade Wochen und tägliche Ansprüche ohne individuelle Uhrzeiten. Der Vorschlag zeigt beide Vergabephasen. Es werden weder E-Mails versendet noch Administratorkonten erstellt. Wiederholung legt das Demo-Jahr nicht doppelt an; im Produktionsmodus wird der Seeder verweigert.


## Gruppen und Zugriffsrechte

Es gibt keine automatisch angelegte Gruppe. Vor der Kinderaufnahme wird eine Gruppe ausdrücklich unter **Gruppen & Admins** erstellt. Vorhandene Administratorkonten ohne Gruppenzuordnung behalten Gesamtzugriff und können Gruppen sowie Kita-Jahre verwalten. Unter **Gruppen & Admins** können diese Konten Gruppen erstellen, umbenennen, Gruppenadmins anlegen und bestehende Konten als Gruppenadmins zuordnen. Das letzte Konto mit Gesamtzugriff bleibt erhalten.

Neue Gruppenadmins werden mit `php artisan careslot:admin gruppe@example.org --group=GRUPPEN-ID` angelegt. Jedes Gruppenadminkonto verwaltet genau eine Gruppe. Ohne `--group` wird ein Konto mit Gesamtzugriff erstellt. Gruppenadmins können ihre Kinder, Kontakte, Wartelisten, Vergaben, Versandaufträge und gruppenbezogene Historie verwalten. Fremde Datensätze sind auch bei direkten Aufrufen oder manipulierten Formular-/Livewire-Daten gesperrt. Gemeinsame Kita-Jahre werden nur durch die Gesamtverwaltung angelegt oder archiviert.

Jede Gruppe hat pro Kita-Jahr eine eigene alphabetische Grundliste und Rotation. Gruppenwechsel eines Kindes erfolgen durch die Gesamtverwaltung. Das Kind wird bei initialisierter Zielwarteliste angehängt; alte Elternlinks werden widerrufen und frühere Ergebnisse bleiben erhalten. Familien und Kontakte werden pro Gruppe verwaltet.

Die Migration zur Entfernung individueller Kinderzeiten bewahrt bestätigte Ergebnisse und ihre historischen Snapshots. Offene Vorschläge werden im Audit gesichert und verworfen; danach ist eine Neuberechnung erforderlich. Die neuen Gruppen- und Zeitmigrationen haben keinen verlustfreien automatischen Rollback. Im Betrieb Vorwärtsmigrationen beziehungsweise ein geprüftes Datenbankbackup verwenden.
