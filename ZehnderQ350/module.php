<?php
class ZehnderComfoAirQ350Native extends IPSModule
{
    // [Label, Protobuf PDO type, Scale factor, Unit]
    private const SENSORS = [
        276 => ['Außentemperatur (°C)', 6, 0.1, '°C'],
        221 => ['Zulufttemperatur (°C)', 6, 0.1, '°C'],
        274 => ['Ablufttemperatur (°C)', 6, 0.1, '°C'],
        275 => ['Fortlufttemperatur (°C)', 6, 0.1, '°C'],
        120 => ['Zuluftvolumenstrom (m³/h)', 2, 1.0, 'm³/h'],
        119 => ['Abluftvolumenstrom (m³/h)', 2, 1.0, 'm³/h'],
        128 => ['Leistungsaufnahme (W)', 2, 1.0, 'W'],
        192 => ['Filter-Restlaufzeit (Tage)', 2, 1.0, 'Tage'],
    ];

    public function Create()
    {
        parent::Create();
        $this->SetVisualizationType(1); // HTML SDK, Symcon >= 7.1
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 56747);
        $this->RegisterPropertyInteger('PIN', 0);
        $this->RegisterPropertyInteger('Interval', 0);
        $this->RegisterPropertyBoolean('EnableControl', false);
        $this->RegisterPropertyString('TileTheme', 'light');
        $this->RegisterPropertyString('TileLayout', 'standard');
        $this->RegisterPropertyInteger('BoostSeconds', 600);
        $this->RegisterPropertyInteger('BypassSeconds', 3600);
        $this->RegisterAttributeInteger('NodeID', 0);
        $this->RegisterTimer('SensorTimer', 0, 'ZQN_ReadSensors($_IPS["TARGET"]);');
        $this->RegisterVariableString('ControlStatus', 'Steuerung / Diagnose', '', 9);
        $this->RegisterVariableString('ControlObserved', 'Rückgelesene Betriebszustände', '', 10);
        $this->RegisterVariableString('NodeInfo', 'ComfoNet Knoten', '', 11);
        $this->RegisterAttributeString('BridgeUUID', '');
        $this->RegisterAttributeString('ClientUUID', '');
        $this->RegisterVariableString('State', 'Verbindungsstatus', '', 0);
        $this->RegisterVariableString('Bridge', 'Gateway UUID', '', 1);
        $this->RegisterVariableString('LastResponse', 'Letzte Antwort', '', 2);
        $this->RegisterVariableString('SensorDiagnostics', 'Sensor-Diagnose', '', 3);
        $this->RegisterVariableString('SensorUpdated', 'Letzte Sensorabfrage', '', 4);
        $this->RegisterVariableString('SensorAvailability', 'Empfangene Sensoren', '', 5);
        $this->RegisterVariableString('ProtocolDiagnostics', 'Protokoll-Diagnose', '', 6);
        $this->RegisterVariableString('RawSensorData', 'Sensor-Rohdaten (Hex)', '', 7);
        $this->RegisterVariableString('RawSensorSummary', 'Rohdaten-Auswertung', '', 8);
        foreach (self::SENSORS as $id => $sensor) {
            $this->RegisterVariableFloat('Sensor' . $id, $sensor[0], '', 10 + $id);
        }
        // Bedienvariablen (Sollwerte). Befehle werden nur nach erfolgreicher RMI-Antwort übernommen.
        $this->registerControlProfile('ZehnderQ350450Native.Speed', ['Abwesend', 'Niedrig', 'Mittel', 'Hoch']);
        $this->registerControlProfile('ZehnderQ350450Native.Mode', ['Automatik', 'Manuell']);
        $this->registerControlProfile('ZehnderQ350450Native.Bypass', ['Automatik', 'Ein', 'Aus']);
        $this->registerControlProfile('ZehnderQ350450Native.TemperatureProfile', ['Normal', 'Kühl', 'Warm']);
        $this->RegisterVariableInteger('Speed', 'Lüftungsstufe (Anforderung)', 'ZehnderQ350450Native.Speed', 20);
        $this->RegisterVariableInteger('Mode', 'Betriebsart (Anforderung)', 'ZehnderQ350450Native.Mode', 21);
        $this->RegisterVariableBoolean('Boost', 'Boost (Anforderung)', '~Switch', 22);
        $this->RegisterVariableInteger('Bypass', 'Bypass (Anforderung)', 'ZehnderQ350450Native.Bypass', 23);
        $this->RegisterVariableInteger('TempProfile', 'Temperaturprofil (Anforderung)', 'ZehnderQ350450Native.TemperatureProfile', 24);
        $this->RegisterVariableString('LastCommand', 'Letzter Steuerbefehl', '', 25);
        foreach (['Speed', 'Mode', 'Boost', 'Bypass', 'TempProfile'] as $ident) {
            $this->EnableAction($ident);
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        if ($this->ReadAttributeString('ClientUUID') === '') {
            $this->WriteAttributeString('ClientUUID', bin2hex(random_bytes(16)));
        }
        $interval = max(0, $this->ReadPropertyInteger('Interval'));
        $this->SetTimerInterval('SensorTimer', $interval > 0 ? max(5, $interval) * 1000 : 0);
        // Nur zyklische TCP-Abfragen: kein Live-Socket und kein Watchdog.
    }

    private function registerControlProfile(string $name, array $labels): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 1);
        }
        foreach ($labels as $value => $label) {
            IPS_SetVariableProfileAssociation($name, (int)$value, $label, '', -1);
        }
    }

    private function varint(int $v): string
    {
        $out = '';
        do {
            $b = $v & 127;
            $v >>= 7;
            $out .= chr($b | ($v > 0 ? 128 : 0));
        } while ($v > 0);
        return $out;
    }

    private function fieldInt(int $field, int $value): string
    {
        return $this->varint($field << 3) . $this->varint($value);
    }

    private function fieldBytes(int $field, string $value): string
    {
        return $this->varint(($field << 3) | 2) . $this->varint(strlen($value)) . $value;
    }

    private function parse(string $buf): array
    {
        $offset = 0;
        $out = [];
        $limit = strlen($buf);
        while ($offset < $limit) {
            $key = $this->readVarint($buf, $offset);
            $number = $key >> 3;
            $wire = $key & 7;
            if ($number === 0) { throw new Exception('Ungültiges Protobuf-Feld'); }
            if ($wire === 0) { $value = $this->readVarint($buf, $offset); }
            elseif ($wire === 2) {
                $length = $this->readVarint($buf, $offset);
                if ($length > $limit - $offset) { throw new Exception('Protobuf-Länge ungültig'); }
                $value = substr($buf, $offset, $length);
                $offset += $length;
            } elseif ($wire === 1 || $wire === 5) {
                $length = ($wire === 1) ? 8 : 4;
                if ($length > $limit - $offset) { throw new Exception('Protobuf-Feld unvollständig'); }
                $value = substr($buf, $offset, $length);
                $offset += $length;
            } else { throw new Exception('Nicht unterstützter Protobuf-Wiretype: ' . $wire); }
            $out[$number] = $value;
        }
        return $out;
    }

    private function readVarint(string $data, int &$offset): int
    {
        $value = 0;
        $shift = 0;
        do {
            if ($offset >= strlen($data) || $shift > 63) { throw new Exception('Varint ungültig'); }
            $b = ord($data[$offset++]);
            $value |= (($b & 127) << $shift);
            $shift += 7;
        } while (($b & 128) !== 0);
        return $value;
    }

    public function Discover(): void
    {
        try {
            $host = trim($this->ReadPropertyString('Host'));
            $target = $host !== '' ? $host : '255.255.255.255';
            if (!filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                throw new Exception('Bitte eine gültige IPv4-Adresse für die Gateway-Suche eintragen.');
            }
            $context = stream_context_create(['socket' => ['so_broadcast' => $target === '255.255.255.255']]);
            // Bind locally so the gateway can reply directly to the source port.
            $socket = @stream_socket_server('udp://0.0.0.0:0', $errno, $errstr, STREAM_SERVER_BIND, $context);
            if ($socket === false) {
                throw new Exception('UDP-Socket konnte nicht geöffnet werden: ' . $errstr);
            }
            try {
                stream_set_timeout($socket, 3);
                if (@stream_socket_sendto($socket, "\x0a\x00", 0, $target . ':56747') !== 2) {
                    throw new Exception('UDP-Anfrage konnte nicht gesendet werden. Lokalen Netzwerkzugriff prüfen oder Gateway-IP eintragen.');
                }
                $reply = @stream_socket_recvfrom($socket, 2048, 0, $sender);
            } finally {
                fclose($socket);
            }
            if (!$reply) {
                throw new Exception('Keine UDP-Antwort. Gateway-IP und Netzwerkzugriff prüfen.');
            }
            $outer = $this->parse($reply);
            if (!isset($outer[2])) { throw new Exception('Keine SearchGatewayResponse'); }
            $inner = $this->parse($outer[2]);
            if (!isset($inner[2]) || strlen($inner[2]) !== 16) { throw new Exception('Gateway-UUID fehlt'); }
            $uuid = bin2hex($inner[2]);
            $this->WriteAttributeString('BridgeUUID', $uuid);
            $this->SetValue('Bridge', $uuid);
            $address = explode(':', $sender, 2)[0];
            $this->SetValue('State', 'Gateway gefunden: ' . $address);
        } catch (Throwable $e) {
            $this->SetValue('State', 'Discovery fehlgeschlagen: ' . $e->getMessage());
        }
    }

    private function sendPacket($socket, string $source, string $destination, int $type, int $ref, string $message = ''): void
    {
        $op = $this->fieldInt(1, $type) . $this->fieldInt(4, $ref);
        $payload = $source . $destination . pack('n', strlen($op)) . $op . $message;
        $packet = pack('N', strlen($payload)) . $payload;
        while ($packet !== '') {
            $written = fwrite($socket, $packet);
            if ($written === false || $written === 0) { throw new Exception('Senden fehlgeschlagen'); }
            $packet = substr($packet, $written);
        }
    }

    private function readExact($socket, int $bytes): string
    {
        $data = '';
        while (strlen($data) < $bytes) {
            $part = fread($socket, $bytes - strlen($data));
            if ($part === false || $part === '') { throw new Exception('Keine vollständige TCP-Antwort'); }
            $data .= $part;
        }
        return $data;
    }

    private function readPacket($socket): array
    {
        $size = unpack('N', $this->readExact($socket, 4))[1];
        if ($size < 34 || $size > 1048576) { throw new Exception('Ungültige Paketgröße'); }
        $data = $this->readExact($socket, $size);
        $opLength = unpack('n', substr($data, 32, 2))[1];
        if ($opLength > $size - 34) { throw new Exception('Ungültige Operationslänge'); }
        return [$this->parse(substr($data, 34, $opLength)), substr($data, 34 + $opLength)];
    }

    public function TestConnection(): void
    {
        try {
            $host = trim($this->ReadPropertyString('Host'));
            if (!filter_var($host, FILTER_VALIDATE_IP)) { throw new Exception('Bitte gültige LAN-IP-Adresse eintragen'); }
            $bridgeHex = $this->ReadAttributeString('BridgeUUID');
            if (strlen($bridgeHex) !== 32) { throw new Exception('Zuerst Gateway-Erkennung ausführen'); }
            $clientHex = $this->ReadAttributeString('ClientUUID');
            $client = hex2bin($clientHex); $bridge = hex2bin($bridgeHex);
            $errno = 0; $errstr = '';
            $socket = @stream_socket_client('tcp://' . $host . ':' . $this->ReadPropertyInteger('Port'), $errno, $errstr, 4);
            if ($socket === false) { throw new Exception('TCP-Verbindung fehlgeschlagen: ' . $errstr); }
            stream_set_timeout($socket, 4);
            try {
                $registration = $this->fieldBytes(1, $client) . $this->fieldInt(2, $this->ReadPropertyInteger('PIN')) . $this->fieldBytes(3, 'IP-Symcon');
                $this->sendPacket($socket, $client, $bridge, 2, 1, $registration);
                $found = false;
                for ($i = 0; $i < 5; $i++) {
                    [$op, $msg] = $this->readPacket($socket);
                    if (($op[1] ?? -1) === 52) {
                        $result = $op[2] ?? 0;
                        if ($result !== 0) { throw new Exception('Registrierung abgelehnt, Code: ' . $result); }
                        $found = true; break;
                    }
                }
                if (!$found) { throw new Exception('Keine Registrierungsbestätigung'); }
                $this->sendPacket($socket, $client, $bridge, 3, 2, '');
                $found = false;
                for ($i = 0; $i < 10; $i++) {
                    [$op, $msg] = $this->readPacket($socket);
                    if (($op[1] ?? -1) === 53) {
                        $result = $op[2] ?? 0;
                        if ($result !== 0) { throw new Exception('Sitzung verweigert, Code: ' . $result); }
                        $found = true; break;
                    }
                }
                if (!$found) { throw new Exception('Keine Sitzungsbestätigung'); }
                $this->SetValue('State', 'Registrierung und Sitzungsaufbau erfolgreich (Testverbindung wieder geschlossen)');
                $this->SetValue('LastResponse', 'StartSessionConfirmType: OK');
                $this->sendPacket($socket, $client, $bridge, 4, 3, '');
            } finally { fclose($socket); }
        } catch (Throwable $e) {
            $this->SetValue('State', 'Verbindungstest fehlgeschlagen: ' . $e->getMessage());
            $this->SendDebug('Connection error', $e->getMessage(), 0);
        }
    }

    // Einmalige, read-only Abfrage. Die LAN C gibt PDO-Werte nur innerhalb einer Sitzung aus.
    public function ReadSensors(): void
    {
        // Prevent overlapping TCP sessions when the requested polling interval
        // is shorter than the duration of a sensor transaction.
        $lockName = 'ZehnderSensorRead_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lockName, 100)) {
            $this->SendDebug('SensorTimer', 'Previous sensor read is still running; this tick is skipped.', 0);
            return;
        }
        $socket = false;
        $sessionStarted = false;
        try {
            $host = trim($this->ReadPropertyString('Host'));
            if (!filter_var($host, FILTER_VALIDATE_IP)) { throw new Exception('Bitte LAN-IP-Adresse konfigurieren'); }
            $bridgeHex = $this->ReadAttributeString('BridgeUUID');
            $clientHex = $this->ReadAttributeString('ClientUUID');
            if (!ctype_xdigit($bridgeHex) || strlen($bridgeHex) !== 32 || !ctype_xdigit($clientHex) || strlen($clientHex) !== 32) {
                throw new Exception('Zuerst Gateway-Erkennung und Registrierung ausführen');
            }
            $bridge = hex2bin($bridgeHex);
            $client = hex2bin($clientHex);
            $errno = 0; $errstr = '';
            $socket = @stream_socket_client('tcp://' . $host . ':' . $this->ReadPropertyInteger('Port'), $errno, $errstr, 4);
            if ($socket === false) { throw new Exception('TCP-Verbindung fehlgeschlagen: ' . $errstr); }
            stream_set_timeout($socket, 2);
            $this->sendPacket($socket, $client, $bridge, 3, 10, '');
            $confirmed = false;
            $deadline = microtime(true) + 6;
            while (microtime(true) < $deadline) {
                try { [$op, $msg] = $this->readPacket($socket); }
                catch (Throwable $e) { if (feof($socket)) { throw $e; } continue; }
                if (($op[1] ?? -1) === 53) {
                    $result = $op[2] ?? 0;
                    if ($result !== 0) { throw new Exception('Sitzungsaufbau verweigert, Code ' . $result); }
                    $confirmed = true;
                    $sessionStarted = true;
                    break;
                }
            }
            if (!$confirmed) { throw new Exception('Keine StartSession-Bestätigung erhalten'); }
            $this->sendPacket($socket, $client, $bridge, 42, 11, ''); // Node discovery (read-only)
            $ref = 12;
            foreach (self::SENSORS as $pdid => $sensor) {
                $request = $this->fieldInt(1, $pdid) . $this->fieldInt(2, 1) . $this->fieldInt(3, $sensor[1]) . $this->fieldInt(4, 0xFFFFFFFF);
                $this->sendPacket($socket, $client, $bridge, 38, $ref++, $request);
            }
            $received = [];
            $acknowledged = [];
            $errors = [];
            $packetTypes = [];
            $unmatched = [];
            $rawMessages = [];
            $initialZeros = [];
            $notificationsPerSensor = [];
            $deadline = microtime(true) + 18;
            // LAN C often sends initial zero placeholders on a new subscription.
            // Never stop just because we have received one frame for each PDID.
            while (microtime(true) < $deadline && count($received) < count(self::SENSORS)) {
                try { [$op, $msg] = $this->readPacket($socket); }
                catch (Throwable $e) {
                    if (feof($socket)) { $errors[] = 'TCP-Verbindung wurde beendet'; break; }
                    continue; // Zeitüberschreitung: bis zur Gesamtablaufzeit weiter warten
                }
                $type = $op[1] ?? -1;
                $packetTypes[$type] = ($packetTypes[$type] ?? 0) + 1;
                if ($type === 39) {
                    $requestRef = $op[4] ?? 0;
                    $pdid = array_keys(self::SENSORS)[$requestRef - 12] ?? null;
                    if ($pdid !== null) {
                        if (($op[2] ?? 0) !== 0) { $errors[] = 'PDID ' . $pdid . ': Bestätigungscode ' . $op[2]; }
                        else { $acknowledged[$pdid] = true; }
                    }
                } elseif ($type === 40) {
                    $fields = $this->parse($msg);
                    $pdid = $fields[1] ?? 0;
                    if (!isset(self::SENSORS[$pdid]) || !isset($fields[2])) {
                        $unmatched[] = 'PDID ' . $pdid . ' / Datenfeld ' . (isset($fields[2]) ? 'ja' : 'nein');
                        continue;
                    }
                    $sensor = self::SENSORS[$pdid];
                    $bytes = $fields[2];
                    $notificationsPerSensor[$pdid] = ($notificationsPerSensor[$pdid] ?? 0) + 1;
                    $rawMessages[$pdid] = 'PDID ' . $pdid . ' | Meldung ' . $notificationsPerSensor[$pdid] . ' | Typ ' . $sensor[1] . ' | Länge ' . strlen($bytes) . ' | Bytes ' . strtoupper(bin2hex($bytes)) . ' | Nachricht ' . strtoupper(bin2hex($msg));
                    $typeId = $sensor[1];
                    if (!in_array($typeId, [2, 6], true) || strlen($bytes) < 2) {
                        $errors[] = 'PDID ' . $pdid . ': Datentyp oder Datenlänge ungültig';
                        continue;
                    }
                    $raw = unpack('v', substr($bytes, 0, 2))[1];
                    // Filter invalid PDO startup markers
                    if ($raw === 65535 || ($typeId === 6 && $raw === 32767)) { continue; }
                    if ($typeId === 6 && $raw >= 32768) { $raw -= 65536; }
                    $value = $raw * $sensor[2];
                    // The first all-zero PDO after a fresh session is often a placeholder.
                    // Keep a zero as a possible real value, but only after we have
                    // received a subsequent frame for this particular PDID.
                    if ($raw === 0 && $notificationsPerSensor[$pdid] === 1) {
                        $initialZeros[$pdid] = true;
                        continue;
                    }
                    $this->SetValue('Sensor' . $pdid, (float) $value);
                    $received[$pdid] = $value;
                }
            }
            $zeroCount = count(array_filter($received, static function ($v) { return $v == 0; }));
            $this->SetValue('RawSensorData', $rawMessages ? implode("\n", $rawMessages) : 'Keine Rohdaten empfangen');
            $this->SetValue('RawSensorSummary', count($notificationsPerSensor) . ' Sensor-IDs mit Telegrammen, ' . array_sum($notificationsPerSensor) . ' Nachrichten insgesamt; ' . count($initialZeros) . ' initiale Nullmeldungen verworfen; ' . count($received) . ' Werte nach Filter; davon ' . $zeroCount . ' Nullwerte.');
            $summary = count($received) . '/' . count(self::SENSORS) . ' Sensorwerte empfangen; '
                . count($acknowledged) . ' Abonnements bestätigt';
            if ($errors) { $summary .= '; Fehler: ' . implode(', ', $errors); }
            if ($initialZeros) { $summary .= '; initiale Nullmeldungen: ' . implode(', ', array_keys($initialZeros)); }
            if (count($received) < count(self::SENSORS)) { $summary .= '; fehlende aktualisierte Sensoren: ' . implode(', ', array_diff(array_keys(self::SENSORS), array_keys($received))); }
            if (!$received) { $summary .= '; nach dem ersten Nulltelegramm wurden noch keine weiteren Messwerte empfangen'; }
            $this->SetValue('SensorDiagnostics', $summary);
            $this->SetValue('SensorAvailability', $received ? implode(', ', array_map('strval', array_keys($received))) : 'Keine Sensormeldung empfangen – angezeigte 0-Werte sind keine Messwerte');
            $typeDescriptions = [];
            foreach ($packetTypes as $packetType => $count) { $typeDescriptions[] = $packetType . ':' . $count; }
            $protoSummary = 'Antworttypen (Typ:Anzahl): ' . ($typeDescriptions ? implode(', ', $typeDescriptions) : 'keine') . '; Bestätigte PDIDs: ' . ($acknowledged ? implode(', ', array_keys($acknowledged)) : 'keine') . '; Benachrichtigungen pro PDID: ' . json_encode($notificationsPerSensor);
            if ($unmatched) { $protoSummary .= '; Unerwartet: ' . implode(', ', array_slice($unmatched, 0, 8)); }
            $this->SetValue('ProtocolDiagnostics', $protoSummary);
            $this->SendDebug('RPDO-Diagnose', $protoSummary, 0);
            $this->SetValue('SensorUpdated', date('Y-m-d H:i:s'));
            $this->SetValue('State', $received ? 'Sensorabfrage abgeschlossen: ' . $summary : 'Sensorabfrage ohne Messwerte: ' . $summary);
            $this->SendDebug('Sensor response', json_encode($received), 0);
            $this->sendTileUpdate();
        } catch (Throwable $e) {
            $this->SetValue('SensorDiagnostics', 'Sensorabfrage fehlgeschlagen: ' . $e->getMessage());
            $this->SetValue('SensorAvailability', 'Keine gültigen aktuellen Messwerte bestätigt');
            $this->SetValue('ProtocolDiagnostics', 'Ausnahme: ' . $e->getMessage());
            $this->SetValue('RawSensorData', 'Fehler beim Abruf: ' . $e->getMessage());
            $this->SetValue('RawSensorSummary', 'Keine neue Rohdatenauswertung');
            $this->SendDebug('Sensor error', $e->getMessage(), 0);
        } finally {
            if (is_resource($socket)) {
                if ($sessionStarted) {
                    try { $this->sendPacket($socket, $client, $bridge, 4, 100, ''); } catch (Throwable $ignored) { }
                }
                fclose($socket);
            }
            IPS_SemaphoreLeave($lockName);
        }
    }



    // Steuerbefehle werden nur nach ausdrücklicher Freigabe versendet. Kein Übernehmen fremder Sitzungen.
    public function RequestAction($ident, $value)
    {
        if ($ident === 'TileRefresh') {
            $this->ReadSensors();
            $this->sendTileUpdate();
            return;
        }
        if ($ident === 'TileReadStatus') {
            $this->ReadControlStatus();
            $this->sendTileUpdate();
            return;
        }
        if (!$this->ReadPropertyBoolean('EnableControl')) {
            throw new Exception('Steuerung gesperrt: erst in der Instanz freigeben');
        }
        try {
            switch ($ident) {
                case 'Speed': $this->SetSpeed((int)$value); break;
                case 'Mode': $this->SetMode((int)$value); break;
                case 'Boost': $this->SetBoost((bool)$value); break;
                case 'Bypass': $this->SetBypass((int)$value); break;
                case 'TempProfile': $this->SetTemperatureProfile((int)$value); break;
                default: throw new Exception('Unbekannte Steueraktion: ' . $ident);
            }
            // Anforderung, nicht gemessener Istzustand. Rücklesung separat prüfen.
            $this->SetValue($ident, $value);
            $this->SetValue('LastCommand', date('Y-m-d H:i:s') . ' | ' . $ident . ' = ' . (int)$value . ' | ' . GetValue($this->GetIDForIdent('ControlStatus')));
            $this->sendTileUpdate();
        } catch (Throwable $e) {
            $this->SetValue('LastCommand', date('Y-m-d H:i:s') . ' | FEHLER ' . $ident . ': ' . $e->getMessage());
            throw $e;
        }
    }

    private function controlTransaction(array $commands, string $label): void
    {
        if (!$this->ReadPropertyBoolean('EnableControl')) {
            throw new Exception('Schreibzugriff in der Instanzkonfiguration nicht freigegeben');
        }
        $socket = false;
        $started = false;
        $node = 0;
        try {
            $host = trim($this->ReadPropertyString('Host'));
            if (!filter_var($host, FILTER_VALIDATE_IP)) { throw new Exception('Ungültige Gateway-IP'); }
            $bridgeHex = $this->ReadAttributeString('BridgeUUID');
            $clientHex = $this->ReadAttributeString('ClientUUID');
            if (strlen($bridgeHex) !== 32 || strlen($clientHex) !== 32 || !ctype_xdigit($bridgeHex) || !ctype_xdigit($clientHex)) {
                throw new Exception('Gateway-Erkennung und Registrierung erforderlich');
            }
            $bridge = hex2bin($bridgeHex); $client = hex2bin($clientHex);
            $socket = @stream_socket_client('tcp://' . $host . ':' . $this->ReadPropertyInteger('Port'), $errno, $errstr, 5);
            if (!$socket) { throw new Exception('TCP nicht erreichbar: ' . $errstr); }
            stream_set_timeout($socket, 2);
            $this->sendPacket($socket, $client, $bridge, 3, 1000, '');
            $deadline = microtime(true) + 8;
            while (microtime(true) < $deadline) {
                try { [$op, $msg] = $this->readPacket($socket); }
                catch (Throwable $e) { if (feof($socket)) { throw $e; } continue; }
                if (($op[1] ?? 0) === 53 && ($op[4] ?? 0) === 1000) {
                    if (($op[2] ?? 0) !== 0) { throw new Exception('Sitzung abgelehnt: ' . $op[2]); }
                    $started = true; break;
                }
            }
            if (!$started) { throw new Exception('Keine Sitzungsbestätigung'); }
            $this->sendPacket($socket, $client, $bridge, 42, 1001);
            // Node wird auf jeder Verbindung erneut erkannt; keine statisch angenommene Node-ID.
            $deadline = microtime(true) + 8;
            while (microtime(true) < $deadline) {
                try { [$op, $msg] = $this->readPacket($socket); }
                catch (Throwable $e) { if (feof($socket)) { throw $e; } continue; }
                if (($op[1] ?? 0) !== 32) { continue; }
                $fields = $this->parse($msg);
                $product = $fields[2] ?? 0;
                $mode = $fields[4] ?? 0;
                if ($product === 1 && $mode !== 1 && isset($fields[1])) {
                    $node = $fields[1]; break;
                }
            }
            if ($node < 1 || $node > 63) { throw new Exception('Kein ComfoAir-Q-Knoten gefunden; Steuerbefehl nicht gesendet'); }
            $this->WriteAttributeInteger('NodeID', $node);
            $this->SetValue('NodeInfo', 'ComfoAir Q, Node-ID ' . $node);
            $reports=[];
            foreach ($commands as $index => $command) {
                $ref = 1100 + $index;
                $payload = $this->fieldInt(1, $node) . $this->fieldBytes(2, $command);
                $this->sendPacket($socket, $client, $bridge, 33, $ref, $payload);
                $found = false;
                $deadline = microtime(true) + 8;
                while (microtime(true) < $deadline) {
                    try { [$op, $msg] = $this->readPacket($socket); }
                    catch (Throwable $e) { if (feof($socket)) { throw $e; } continue; }
                    if (($op[4] ?? 0) !== $ref || ($op[1] ?? 0) !== 34) { continue; }
                    if (($op[2] ?? 0) !== 0) { throw new Exception('Gateway-Fehler ' . $op[2]); }
                    $f = $this->parse($msg);
                    if (($f[1] ?? 0) !== 0) { throw new Exception('ComfoNet-RMI-Fehler ' . $f[1]); }
                    $reports[] = strtoupper(bin2hex($f[2] ?? ''));
                    $found = true;
                    break;
                }
                if (!$found) { throw new Exception('Keine Bestätigung für Steuertelegramm ' . ($index + 1)); }
            }
            $this->SetValue('ControlStatus', date('Y-m-d H:i:s') . ': ' . $label . ' bestätigt (RMI), Antworten: ' . implode(', ', $reports) . '. Betriebszustand noch nicht rückgelesen.');
        } catch (Throwable $e) {
            $this->SetValue('ControlStatus', date('Y-m-d H:i:s') . ': ' . $label . ' FEHLER: ' . $e->getMessage());
            $this->SendDebug('Steuerung', $e->getMessage(), 0);
            throw $e;
        } finally {
            if (is_resource($socket)) {
                if ($started) { try { $this->sendPacket($socket, $client, $bridge, 4, 1200); } catch (Throwable $ignored) {} }
                fclose($socket);
            }
        }
    }

    private function timerCommand(int $subunit, int $timer, int $value, int $duration): string
    {
        // Schedule RMI: 84 15 subunit timer + delay(int32 LE) + duration(int32 LE) + value(uint8)
        return pack('CCCC', 0x84, 0x15, $subunit, $timer) . pack('V', 0) . pack('V', $duration) . chr($value);
    }
    private function cancelCommand(int $subunit, int $timer): string
    {
        return pack('CCCC', 0x85, 0x15, $subunit, $timer);
    }
    public function SetSpeed(int $speed): void
    {
        if ($speed < 0 || $speed > 3) { throw new Exception('Lüftungsstufe muss 0–3 sein'); }
        $this->controlTransaction([$this->timerCommand(1, 1, $speed, 1)], 'Lüftungsstufe ' . $speed);
    }
    public function SetMode(int $mode): void
    {
        if (!in_array($mode, [0, 1], true)) { throw new Exception('Modus: 0 Automatik, 1 Manuell'); }
        $commands = $mode === 0 ? [$this->cancelCommand(8, 1), $this->cancelCommand(1, 1)] : [$this->timerCommand(8, 1, 1, 1)];
        $this->controlTransaction($commands, $mode === 0 ? 'Automatik' : 'Manuell');
    }
    public function SetBoost(bool $on): void
    {
        $seconds = max(60, min(86400, $this->ReadPropertyInteger('BoostSeconds')));
        $this->controlTransaction([$on ? $this->timerCommand(1, 6, 3, $seconds) : $this->cancelCommand(1, 6)], $on ? 'Boost EIN' : 'Boost AUS');
    }
    public function SetBypass(int $mode): void
    {
        if ($mode < 0 || $mode > 2) { throw new Exception('Bypass: 0 Auto, 1 Ein, 2 Aus'); }
        $seconds = max(60, min(86400, $this->ReadPropertyInteger('BypassSeconds')));
        $command = $mode === 0 ? $this->cancelCommand(2, 1) : $this->timerCommand(2, 1, $mode, $seconds);
        $this->controlTransaction([$command], 'Bypass ' . ['Auto', 'Ein', 'Aus'][$mode]);
    }
    public function SetTemperatureProfile(int $mode): void
    {
        if ($mode < 0 || $mode > 2) { throw new Exception('Temperaturprofil: 0 Normal, 1 Kühl, 2 Warm'); }
        $this->controlTransaction([$this->timerCommand(3, 1, $mode, 0xFFFFFFFF)], 'Temperaturprofil ' . ['Normal', 'Kühl', 'Warm'][$mode]);
    }

    public function ReadControlStatus(): void
    {
        // Separater Befehl: status gelesen über RMI GETTIMERENTRY (0x83)
        // Die Steuerung bleibt dabei unverändert.
        $socket = false; $started = false;
        try {
            $host = trim($this->ReadPropertyString('Host'));
            $bridge = hex2bin($this->ReadAttributeString('BridgeUUID'));
            $client = hex2bin($this->ReadAttributeString('ClientUUID'));
            if (!$bridge || !$client || !filter_var($host, FILTER_VALIDATE_IP)) { throw new Exception('Verbindung nicht konfiguriert'); }
            $socket = @stream_socket_client('tcp://' . $host . ':' . $this->ReadPropertyInteger('Port'), $errno, $errstr, 5);
            if (!$socket) { throw new Exception('TCP-Verbindung fehlgeschlagen'); }
            stream_set_timeout($socket, 2);
            $this->sendPacket($socket, $client, $bridge, 3, 2000);
            $node = 0; $deadline = microtime(true) + 8;
            while (microtime(true) < $deadline) {
                try { [$op,$msg] = $this->readPacket($socket); } catch (Throwable $e) { if (feof($socket)) {throw $e;} continue; }
                if (($op[1] ?? 0) === 53 && ($op[4] ?? 0) === 2000) {
                    if (($op[2] ?? 0) !== 0) { throw new Exception('Session verweigert'); }
                    $started = true; break;
                }
            }
            if (!$started) { throw new Exception('Session-Timeout'); }
            $this->sendPacket($socket,$client,$bridge,42,2001);
            $deadline=microtime(true)+8;
            while(microtime(true)<$deadline) {
                try { [$op,$msg]=$this->readPacket($socket); } catch(Throwable $e) {if(feof($socket)){throw $e;}continue;}
                if(($op[1]??0)!==32){continue;}
                $f=$this->parse($msg);
                if(($f[2]??0)===1 && ($f[4]??0)!==1) {$node=(int)$f[1];break;}
            }
            if(!$node){throw new Exception('ComfoAir-Q-Knoten fehlt');}
            $timers=['Lüftungsstufe'=>[1,1],'Manuell'=>[8,1],'Boost'=>[1,6],'Bypass'=>[2,1],'Temperaturprofil'=>[3,1]];
            $observed=[]; $ref=2100;
            foreach($timers as $label=>$ids) {
                $this->sendPacket($socket,$client,$bridge,33,$ref,$this->fieldInt(1,$node).$this->fieldBytes(2,pack('CCCC',0x83,0x15,$ids[0],$ids[1])));
                $done=false; $deadline=microtime(true)+7;
                while(microtime(true)<$deadline) {
                    try {[$op,$msg]=$this->readPacket($socket);}catch(Throwable $e){if(feof($socket)){throw $e;}continue;}
                    if(($op[1]??0)!==34||($op[4]??0)!==$ref){continue;}
                    if(($op[2]??0)!==0){throw new Exception($label.' Gateway-Fehler '.$op[2]);}
                    $f=$this->parse($msg);
                    if(($f[1]??0)!==0){throw new Exception($label.' RMI-Fehler '.$f[1]);}
                    $raw=$f[2]??'';
                    if(strlen($raw)<14){throw new Exception($label.' unerwartete Antwort: '.strtoupper(bin2hex($raw)));}
                    $v=unpack('Cactive/Vdelay/Vduration/Vremaining/Cvalue',substr($raw,0,14));
                    $observed[]=$label.': '.($v['active']?'aktiv':'inaktiv').', Wert '.$v['value'].', Restzeit '.$v['remaining'].' s';
                    $done=true;break;
                }
                if(!$done){throw new Exception($label.' ohne Antwort');}
                $ref++;
            }
            $this->SetValue('ControlObserved',date('Y-m-d H:i:s')." | ".implode(' | ',$observed));
            $this->sendTileUpdate();
        } catch(Throwable $e) {
            $this->SetValue('ControlObserved','Rücklesefehler: '.$e->getMessage());
            $this->SendDebug('Rücklesen',$e->getMessage(),0);
        } finally {
            if(is_resource($socket)){if($started){try{$this->sendPacket($socket,$client,$bridge,4,2200);}catch(Throwable $ignored){}}fclose($socket);}
        }
    }

    // Native HTML-SDK device tile, kept separate from the polling / TCP implementation.
    private function tileState(): array
    {
        $state = [
            'enabled' => $this->ReadPropertyBoolean('EnableControl'),
            'theme' => $this->ReadPropertyString('TileTheme'),
            'interval' => $this->ReadPropertyInteger('Interval'),
            'values' => [],
            'requests' => [],
            'updated' => '',
            'connection' => '',
            'diagnostics' => '',
            'command' => '',
            'observed' => ''
        ];
        foreach (self::SENSORS as $pdid => $_) {
            $id = @$this->GetIDForIdent('Sensor' . $pdid);
            $state['values'][(string)$pdid] = $id ? GetValue($id) : null;
        }
        foreach (['Speed', 'Mode', 'Boost', 'Bypass', 'TempProfile'] as $ident) {
            $id = @$this->GetIDForIdent($ident);
            $state['requests'][$ident] = $id ? GetValue($id) : null;
        }
        foreach (['SensorUpdated' => 'updated', 'State' => 'connection', 'SensorDiagnostics' => 'diagnostics', 'LastCommand' => 'command', 'ControlObserved' => 'observed'] as $ident => $key) {
            $id = @$this->GetIDForIdent($ident);
            if ($id) { $state[$key] = (string)GetValue($id); }
        }
        return $state;
    }

    private function sendTileUpdate(): void
    {
        $this->UpdateVisualizationValue(json_encode($this->tileState(), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function GetVisualizationTile()
    {
        $template = $this->ReadPropertyString('TileLayout') === 'mini' ? 'module-mini.html' : 'module.html';
        $html = file_get_contents(__DIR__ . '/' . $template);
        $state = json_encode($this->tileState(), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return str_replace('__INITIAL_STATE__', $state ?: '{}', $html);
    }

}
