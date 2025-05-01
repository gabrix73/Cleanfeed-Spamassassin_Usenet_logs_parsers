<?php
$logfile = '/var/log/spamassassin.log';

function parse_log($file, $minscore = 0.0, $sender_filter = '') {
    $entries = [];
    if (!file_exists($file)) return [];

    $lines = file($file);
    foreach ($lines as $line) {
        if (strpos($line, 'spamd: result:') !== false) {
            // Score
            if (preg_match('/clean message \(([\d\.]+)\//', $line, $scoreMatch)) {
                $score = floatval($scoreMatch[1]);
            } elseif (preg_match('/spamd: result: .*? (\d+) /', $line, $scoreMatch)) {
                $score = floatval($scoreMatch[1]);
            } else {
                continue;
            }

            // Sender
            if (preg_match('/mid=<([^>]+@[^>]+)>/', $line, $midMatch)) {
                $sender = $midMatch[1];
            } else {
                $sender = '(unknown)';
            }

            // Subject
            if (preg_match('/Subject:\s*(.*?)\s*(?:scantime=|user=|uid=|required_score=|mid=|autolearn=)/', $line, $subMatch)) {
                $subject = trim($subMatch[1]);
            } else {
                $subject = '(no subject)';
            }

            if ($score >= $minscore && (empty($sender_filter) || stripos($sender, $sender_filter) !== false)) {
                $entries[] = [
                    'score' => $score,
                    'sender' => $sender,
                    'subject' => $subject
                ];
            }
        }
    }
    return $entries;
}

$minscore = isset($_GET['minscore']) ? floatval($_GET['minscore']) : 0.0;
$sender_filter = isset($_GET['sender']) ? $_GET['sender'] : '';

$entries = parse_log($logfile, $minscore, $sender_filter);

// Ordina per score
usort($entries, fn($a, $b) => $b['score'] <=> $a['score']);
$entries = array_slice($entries, 0, 20);

// Conteggio domini
$domain_counts = [];
foreach ($entries as $e) {
    $domain = explode('@', $e['sender'])[1] ?? 'unknown';
    $domain_counts[$domain] = ($domain_counts[$domain] ?? 0) + 1;
}

// Prepara dati grafici
$scores = array_column($entries, 'score');
$senders = array_map(fn($e) => explode('@', $e['sender'])[1] ?? $e['sender'], $entries);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>SpamAssassin Log Viewer</title>
    <meta http-equiv="refresh" content="60">
    <style>
        body { font-family: sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { padding: 8px; border: 1px solid #ccc; }
        th { background-color: #eee; }
        form { margin-bottom: 20px; }
        #domainChart { max-width: 400px; margin: 0 auto; display: block; }
    </style>
</head>
<body>
    <h1>SpamAssassin Log Viewer</h1>

    <form method="get">
        <label>Mittente contiene: <input type="text" name="sender" value="<?= htmlspecialchars($sender_filter) ?>"></label>
        <label>Punteggio minimo: <input type="number" step="0.1" name="minscore" value="<?= htmlspecialchars($minscore) ?>"></label>
        <button type="submit">Filtra</button>
    </form>

    <canvas id="scoreChart" width="800" height="300"></canvas>
    <h2>Distribuzione per dominio</h2>
    <canvas id="domainChart" width="600" height="300"></canvas>

    <table>
        <tr><th>Score</th><th>Sender</th><th>Subject</th></tr>
        <?php foreach ($entries as $entry): ?>
        <tr>
            <td><?= htmlspecialchars($entry['score']) ?></td>
            <td><?= htmlspecialchars($entry['sender']) ?></td>
            <td><?= htmlspecialchars($entry['subject']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Grafico a barre: punteggi
        const ctx = document.getElementById('scoreChart').getContext('2d');
        const chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($senders) ?>,
                datasets: [{
                    label: 'Spam Score',
                    data: <?= json_encode($scores) ?>,
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: 'y',
                scales: { x: { beginAtZero: true } }
            }
        });

        // Notifiche per score > soglia
        const threshold = 8.0;
        const highScores = <?= json_encode(array_filter($entries, fn($e) => $e['score'] >= 8.0)) ?>;
        if (highScores.length > 0 && "Notification" in window) {
            if (Notification.permission !== "granted") {
                Notification.requestPermission();
            } else {
                highScores.forEach(entry => {
                    new Notification("Spam score elevato", {
                        body: `Da ${entry.sender} - Score: ${entry.score}`
                    });
                });
            }
        }

        // Grafico a torta: domini
        const domainCtx = document.getElementById('domainChart').getContext('2d');
        const domainChart = new Chart(domainCtx, {
            type: 'pie',
            data: {
                labels: <?= json_encode(array_keys($domain_counts)) ?>,
                datasets: [{
                    label: 'Messaggi per dominio',
                    data: <?= json_encode(array_values($domain_counts)) ?>,
                    backgroundColor: [
                        '#f87171', '#60a5fa', '#34d399', '#fbbf24', '#a78bfa',
                        '#fb7185', '#f472b6', '#c084fc', '#a3e635', '#38bdf8',
                        '#facc15', '#4ade80', '#818cf8', '#f472b6', '#06b6d4'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'right' }
                }
            }
        });
    </script>
</body>
</html>
