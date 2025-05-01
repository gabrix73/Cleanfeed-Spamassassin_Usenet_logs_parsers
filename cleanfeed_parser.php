<?php
// Percorso del file statistiche grezzo generato da Cleanfeed
// *** VERIFICA QUESTO PERCORSO sul tuo sistema! ***
$stats_file_path = '/var/spool/news/cleanfeed/html/cleanfeed.stats';

// --- Configurazione ---
// Intervallo di refresh della pagina in secondi (0 per disabilitare)
// Idealmente uguale o maggiore di 'stats_interval' di Cleanfeed (300)
$refresh_interval_seconds = 300;

// --- Funzione Helper per il Parsing (ESEMPIO - DA ADATTARE!) ---
// DEVI modificare questa funzione in base al formato REALE del file cleanfeed.stats
function parse_cleanfeed_stats($filepath) {
    $stats = [];
    if (!file_exists($filepath) || !is_readable($filepath)) {
        // Verifica i permessi! Il web server (es. www-data) deve poter leggere il file.
        return ['error' => "File non trovato o non leggibile: " . htmlspecialchars($filepath) . ". Controlla permessi e percorso."];
    }

    $lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
         return ['error' => "Impossibile leggere il file: " . htmlspecialchars($filepath)];
    }

    $stats['raw_lines'] = $lines; // Mantiene le linee grezze per debug

    // Logica di Parsing ESEMPLIFICATIVA (ADATTARE!)
    // Ipotizza un formato chiave: valore o chiave = valore per linea
    $parsed_data = [];
    $reject_reasons = []; // Prova a estrarre contatori specifici
    foreach ($lines as $line) {
        $line = trim($line);
         // Ignora commenti o linee vuote (anche se file() dovrebbe già gestirlo)
        if (empty($line) || $line[0] === '#') continue;

        // Tentativo di parsing chiave/valore
        if (preg_match('/^([a-zA-Z0-9_.\-]+)\s*[:=]\s*(.*)$/', $line, $matches)) {
            $key = trim($matches[1]);
            $value = trim($matches[2]);
            $parsed_data[$key] = $value;

            // Identificazione speculativa dei contatori di rifiuto
            // CERCA NEL TUO FILE le chiavi usate per i rifiuti (es. reject_, CF., emp., saveart labels...)
             if (is_numeric($value) && $value > 0) {
                // Aggiungi condizioni per identificare chiavi di rifiuto
                if (stripos($key, 'reject') !== false ||
                    stripos($key, 'cancel') !== false ||
                    strpos($key, 'rej.') === 0 ||
                    strpos($key, 'CF.') === 0 ||  // Dalle tue regole saveart()
                    strpos($key, 'emp.') === 0 ||  // Dalle tue regole saveart()
                    strpos($key, 'mi5') === 0 ||   // Dalle tue regole saveart()
                    strpos($key, 'subject') === 0) { // Dalle tue regole saveart()
                   $reject_reasons[$key] = $value;
                }
             }
        } elseif (strpos($line, ':') === false && strpos($line, '=') === false && count(explode(' ', $line)) === 2) {
            // Tentativo per formato "chiave valore" (separati da spazio)
            list($key, $value) = explode(' ', $line, 2);
             $parsed_data[trim($key)] = trim($value);
             // Applica qui la stessa logica di identificazione rifiuti se necessario
        }
        // Aggiungi altre regole di parsing se il formato è diverso...
    }
     $stats['parsed'] = $parsed_data;
     $stats['reject_counts'] = $reject_reasons;

     // Tempo ultima modifica file come riferimento
     $mod_time = @filemtime($filepath);
     if ($mod_time !== false) {
         $stats['last_update_timestamp'] = $mod_time;
         // Imposta il timezone corretto se necessario
         // date_default_timezone_set('Europe/Rome');
         $stats['last_update_readable'] = date('Y-m-d H:i:s T', $mod_time);
     } else {
         $stats['last_update_readable'] = 'N/D';
     }

    return $stats;
}

// --- Ottieni le Statistiche ---
$cleanfeed_stats = parse_cleanfeed_stats($stats_file_path);

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Statistiche Cleanfeed Personalizzate</title>
    <?php if ($refresh_interval_seconds > 0): ?>
    <meta http-equiv="refresh" content="<?php echo $refresh_interval_seconds; ?>">
    <?php endif; ?>
    <style>
        body { font-family: sans-serif; margin: 20px; background-color: #f4f4f4; color: #333; }
        h1, h2 { color: #0056b3; border-bottom: 2px solid #0056b3; padding-bottom: 5px;}
        .stats-section { border: 1px solid #ddd; padding: 20px; margin-bottom: 25px; background-color: #fff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        table { border-collapse: collapse; width: 100%; margin-top: 15px;}
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background-color: #007bff; color: white; }
        tr:nth-child(even) { background-color: #f2f2f2; }
        pre { background-color: #e9ecef; padding: 15px; border: 1px solid #ced4da; border-radius: 4px; white-space: pre-wrap; word-wrap: break-word; max-height: 400px; overflow-y: auto; font-family: monospace; }
        .error { color: #dc3545; background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 5px; }
        #last-update { font-size: 0.9em; color: #555; margin-bottom: 20px; text-align: right; }
    </style>
</head>
<body>
    <h1>Statistiche Cleanfeed</h1>

    <?php if (isset($cleanfeed_stats['error'])): ?>
        <div class="error">
            <h2>Errore nel Caricamento Statistiche</h2>
            <p><?php echo $cleanfeed_stats['error']; ?></p>
        </div>
    <?php else: ?>
        <div id="last-update">
            Ultimo aggiornamento file: <?php echo htmlspecialchars($cleanfeed_stats['last_update_readable'] ?? 'N/D'); ?>
            <?php if ($refresh_interval_seconds > 0): ?>
            (Refresh pagina ogni <?php echo $refresh_interval_seconds; ?> sec)
            <?php endif; ?>
        </div>

        <div class="stats-section">
            <h2>Contatori Rifiuti / Eventi Rilevanti</h2>
            <?php if (!empty($cleanfeed_stats['reject_counts'])): ?>
                <table>
                    <thead>
                        <tr><th>Chiave Evento/Rifiuto</th><th>Conteggio</th></tr>
                    </thead>
                    <tbody>
                        <?php
                        // Ordina per conteggio decrescente
                        arsort($cleanfeed_stats['reject_counts']);
                        foreach ($cleanfeed_stats['reject_counts'] as $key => $value):
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($key); ?></td>
                                <td><?php echo htmlspecialchars($value); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>Nessun contatore specifico identificato o valore zero. Potrebbe essere necessario adattare la logica di parsing nello script PHP in base al contenuto effettivo del file <code>cleanfeed.stats</code> e alle chiavi usate da `saveart`.</p>
            <?php endif; ?>
        </div>

         <div class="stats-section">
            <h2>Statistiche Generali (Dati Parsati)</h2>
             <?php if (!empty($cleanfeed_stats['parsed'])): ?>
                 <table>
                     <thead>
                         <tr><th>Chiave</th><th>Valore</th></tr>
                     </thead>
                     <tbody>
                         <?php
                         ksort($cleanfeed_stats['parsed']); // Ordina per chiave
                         foreach ($cleanfeed_stats['parsed'] as $key => $value):
                             // Opzionale: Salta le chiavi già mostrate sopra
                             // if (array_key_exists($key, $cleanfeed_stats['reject_counts'])) continue;
                         ?>
                             <tr>
                                 <td><?php echo htmlspecialchars($key); ?></td>
                                 <td><?php echo htmlspecialchars($value); ?></td>
                             </tr>
                         <?php endforeach; ?>
                     </tbody>
                 </table>
             <?php else: ?>
                  <p>Nessun dato generale parsato disponibile. Controlla il file e la logica di parsing.</p>
             <?php endif; ?>
        </div>

        <div class="stats-section">
            <h2>Contenuto Raw del File (<code><?php echo htmlspecialchars(basename($stats_file_path)); ?></code>)</h2>
            <p>Utile per debuggare il parsing:</p>
            <?php if (!empty($cleanfeed_stats['raw_lines'])): ?>
                <pre><?php echo htmlspecialchars(implode("\n", $cleanfeed_stats['raw_lines'])); ?></pre>
            <?php else: ?>
                 <p>Contenuto raw non disponibile o file vuoto.</p>
            <?php endif; ?>
        </div>

    <?php endif; ?>

</body>
</html>
