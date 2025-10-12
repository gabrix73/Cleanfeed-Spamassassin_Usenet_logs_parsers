<?php
/**
 * SpamAssassin Log Viewer - Fixed Parser
 * Parses "spamd: result:" lines correctly
 */

$logfile = '/var/log/spamassassin.log';
$dbfile = '/var/www/usenet/data/spam_stats.db';

// ============================================================================
// DATABASE INITIALIZATION
// ============================================================================

function init_database($dbfile) {
    try {
        $db = new PDO("sqlite:$dbfile");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $db->exec("CREATE TABLE IF NOT EXISTS spam_domains (
            domain TEXT PRIMARY KEY,
            count INTEGER DEFAULT 0,
            total_score REAL DEFAULT 0,
            avg_score REAL DEFAULT 0,
            last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS spam_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            domain TEXT,
            score REAL,
            spam_type TEXT,
            message_id TEXT
        )");

        $db->exec("CREATE INDEX IF NOT EXISTS idx_domain ON spam_log(domain)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_timestamp ON spam_log(timestamp)");

        return $db;
    } catch (PDOException $e) {
        $db = new PDO("sqlite::memory:");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $db;
    }
}

// ============================================================================
// LOG PARSING (FIXED)
// ============================================================================

function parse_log($file, $minscore = 0.0, $sender_filter = '', $spam_type_filter = '') {
    $entries = [];
    if (!file_exists($file)) return [];

    $lines = file($file);
    foreach ($lines as $line) {
        if (strpos($line, 'spamd: result:') === false) continue;

        // Extract score from result line
        // Format: "spamd: result: Y 5.7 - RULES..." or "spamd: result: . 2.0 - RULES..."
        if (!preg_match('/spamd: result: ([Y\.]) ([\d\.]+) /', $line, $resultMatch)) {
            continue;
        }

        $is_spam = ($resultMatch[1] === 'Y');
        $score = floatval($resultMatch[2]);

        // Extract domain from Message-ID (mid=<...@domain>)
        if (preg_match('/mid=<[^@]+@([^>]+)>/', $line, $midMatch)) {
            $sender = $midMatch[1];
        } else {
            $sender = '(unknown)';
        }

        // Extract subject (if available)
        $subject = '(no subject)';

        // Extract spam type from rules
        $spam_type = 'unknown';
        if (preg_match('/- (.+?) scantime=/', $line, $rulesMatch)) {
            $rules = explode(',', $rulesMatch[1]);

            if (in_array('USENET_CROSSPOST_EXCESSIVE', $rules) ||
                in_array('USENET_CROSSPOST_7TO10', $rules) ||
                in_array('USENET_CROSSPOST_MASSIVE', $rules)) {
                $spam_type = 'crosspost';
            } elseif (in_array('USENET_TRACKER_MID', $rules) ||
                      in_array('TRACKER_ID', $rules)) {
                $spam_type = 'tracker_mid';
            } elseif (in_array('FORGED_GMAIL_RCVD', $rules) ||
                      in_array('FREEMAIL_FORGED_FROMDOMAIN', $rules)) {
                $spam_type = 'forgery';
            } elseif (in_array('CONTAINS_BASE64', $rules) ||
                      in_array('USENET_BASE64_NOMIME', $rules)) {
                $spam_type = 'base64';
            } elseif (in_array('MISSING_HEADERS', $rules)) {
                $spam_type = 'malformed';
            }
        }

        // Apply filters
        if ($score < $minscore) continue;
        if (!empty($sender_filter) && stripos($sender, $sender_filter) === false) continue;
        if (!empty($spam_type_filter) && $spam_type !== $spam_type_filter) continue;

        $entries[] = [
            'score' => $score,
            'sender' => $sender,
            'subject' => $subject,
            'is_spam' => $is_spam,
            'spam_type' => $spam_type,
            'message_id' => $sender // Use domain as message_id for simplicity
        ];
    }
    return $entries;
}

// ============================================================================
// DATABASE UPDATE
// ============================================================================

function update_database($db, $entries) {
    if (!$db) return;

    $db->beginTransaction();

    foreach ($entries as $entry) {
        $domain = $entry['sender'];
        $score = $entry['score'];
        $spam_type = $entry['spam_type'];
        $message_id = $entry['message_id'];

        // Update spam_domains
        $stmt = $db->prepare("INSERT INTO spam_domains (domain, count, total_score, avg_score, last_seen)
                              VALUES (?, 1, ?, ?, datetime('now'))
                              ON CONFLICT(domain) DO UPDATE SET
                              count = count + 1,
                              total_score = total_score + ?,
                              avg_score = (total_score + ?) / (count + 1),
                              last_seen = datetime('now')");
        $stmt->execute([$domain, $score, $score, $score, $score]);

        // Insert into spam_log
        $stmt = $db->prepare("INSERT INTO spam_log (domain, score, spam_type, message_id)
                              VALUES (?, ?, ?, ?)");
        $stmt->execute([$domain, $score, $spam_type, $message_id]);
    }

    $db->commit();
}

// ============================================================================
// DATA RETRIEVAL
// ============================================================================

function get_top_domains($db, $limit = 20) {
    if (!$db) return [];

    $stmt = $db->prepare("SELECT domain, count, avg_score, last_seen
                          FROM spam_domains
                          ORDER BY count DESC
                          LIMIT ?");
    $stmt->execute([$limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_trend_data($db, $days = 7) {
    if (!$db) return [];

    $stmt = $db->prepare("SELECT date(timestamp) as date, COUNT(*) as count, AVG(score) as avg_score
                          FROM spam_log
                          WHERE timestamp >= datetime('now', '-' || ? || ' days')
                          GROUP BY date(timestamp)
                          ORDER BY date");
    $stmt->execute([$days]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================================
// MAIN EXECUTION
// ============================================================================

$db = init_database($dbfile);

// Get parameters
$minscore = isset($_GET['minscore']) ? floatval($_GET['minscore']) : 0.0;
$sender_filter = isset($_GET['sender']) ? $_GET['sender'] : '';
$spam_type_filter = isset($_GET['spam_type']) ? $_GET['spam_type'] : '';
$export_csv = isset($_GET['export']) && $_GET['export'] === 'csv';

// Parse log
$entries = parse_log($logfile, $minscore, $sender_filter, $spam_type_filter);

// Update database with new entries (only if score >= 5.0 to reduce noise)
$spam_entries = array_filter($entries, fn($e) => $e['score'] >= 5.0);
if (!empty($spam_entries)) {
    update_database($db, $spam_entries);
}

// CSV Export
if ($export_csv) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="spam_stats_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Score', 'Sender', 'Subject', 'Type', 'Spam']);

    foreach ($entries as $entry) {
        fputcsv($output, [
            $entry['score'],
            $entry['sender'],
            $entry['subject'],
            $entry['spam_type'],
            $entry['is_spam'] ? 'Yes' : 'No'
        ]);
    }

    fclose($output);
    exit;
}

// Sort and limit entries for display
usort($entries, fn($a, $b) => $b['score'] <=> $a['score']);
$display_entries = array_slice($entries, 0, 20);

// Get statistics
$top_domains = get_top_domains($db, 20);
$trend_data = get_trend_data($db, 7);

// Count by domain (current view)
$domain_counts = [];
foreach ($display_entries as $e) {
    $domain_counts[$e['sender']] = ($domain_counts[$e['sender']] ?? 0) + 1;
}

// Prepare chart data
$scores = array_column($display_entries, 'score');
$senders = array_map(fn($e) => $e['sender'], $display_entries);
$spam_labels = array_map(fn($e) => $e['is_spam'] ? 'SPAM' : 'Clean', $display_entries);

// Trend chart data
$trend_dates = array_column($trend_data, 'date');
$trend_counts = array_column($trend_data, 'count');
$trend_scores = array_column($trend_data, 'avg_score');

// Stats
$total_entries = count($entries);
$spam_count = count(array_filter($entries, fn($e) => $e['is_spam']));
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>SpamAssassin Log Viewer</title>
    <meta http-equiv="refresh" content="60">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #007bff;
            padding-bottom: 10px;
        }
        h2 {
            color: #555;
            margin-top: 30px;
        }
        .filters {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }
        .filters label {
            font-weight: 600;
        }
        .filters input, .filters select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .filters button {
            padding: 8px 20px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
        }
        .filters button:hover {
            background: #0056b3;
        }
        .export-btn {
            background: #28a745 !important;
        }
        .export-btn:hover {
            background: #1e7e34 !important;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            border-radius: 8px;
            color: white;
        }
        .stat-card h3 {
            margin: 0 0 10px 0;
            font-size: 14px;
            opacity: 0.9;
        }
        .stat-card .value {
            font-size: 32px;
            font-weight: bold;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            margin-top: 20px;
            background: white;
        }
        th, td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: left;
        }
        th {
            background-color: #007bff;
            color: white;
            font-weight: 600;
        }
        tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        tr:hover {
            background-color: #e9ecef;
        }
        .spam-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
        }
        .spam-yes {
            background: #dc3545;
            color: white;
        }
        .spam-no {
            background: #28a745;
            color: white;
        }
        .score-high { color: #dc3545; font-weight: bold; }
        .score-medium { color: #ffc107; font-weight: bold; }
        .score-low { color: #28a745; }
        canvas {
            max-height: 400px;
            margin: 20px 0;
        }
        .chart-container {
            position: relative;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🛡️ SpamAssassin Log Viewer</h1>

        <form method="get" class="filters">
            <div>
                <label>Dominio:</label>
                <input type="text" name="sender" value="<?= htmlspecialchars($sender_filter) ?>" placeholder="es: bofh.it">
            </div>
            <div>
                <label>Score minimo:</label>
                <input type="number" step="0.1" name="minscore" value="<?= htmlspecialchars($minscore) ?>" placeholder="0.0">
            </div>
            <div>
                <label>Tipo spam:</label>
                <select name="spam_type">
                    <option value="">Tutti</option>
                    <option value="crosspost" <?= $spam_type_filter === 'crosspost' ? 'selected' : '' ?>>Crosspost</option>
                    <option value="tracker_mid" <?= $spam_type_filter === 'tracker_mid' ? 'selected' : '' ?>>Tracker MID</option>
                    <option value="forgery" <?= $spam_type_filter === 'forgery' ? 'selected' : '' ?>>Forgery</option>
                    <option value="base64" <?= $spam_type_filter === 'base64' ? 'selected' : '' ?>>Base64</option>
                    <option value="malformed" <?= $spam_type_filter === 'malformed' ? 'selected' : '' ?>>Malformed</option>
                </select>
            </div>
            <button type="submit">🔍 Filtra</button>
            <button type="button" class="export-btn" onclick="window.location.href='?export=csv&minscore=<?= $minscore ?>&sender=<?= urlencode($sender_filter) ?>&spam_type=<?= urlencode($spam_type_filter) ?>'">📥 Export CSV</button>
        </form>

        <div class="stats-grid">
            <div class="stat-card">
                <h3>MESSAGGI ANALIZZATI</h3>
                <div class="value"><?= $total_entries ?></div>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                <h3>SPAM RILEVATO</h3>
                <div class="value"><?= $spam_count ?></div>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                <h3>DOMINI TRACCIATI</h3>
                <div class="value"><?= count($top_domains) ?></div>
            </div>
        </div>

        <?php if (!empty($trend_data)): ?>
        <h2>📊 Trend ultimi 7 giorni</h2>
        <div class="chart-container">
            <canvas id="trendChart"></canvas>
        </div>
        <?php endif; ?>

        <h2>📈 Score Distribution</h2>
        <div class="chart-container">
            <canvas id="scoreChart"></canvas>
        </div>

        <h2>🏆 Top 20 Domini Spam (All-Time)</h2>
        <table>
            <tr>
                <th>#</th>
                <th>Dominio</th>
                <th>Conteggio</th>
                <th>Score Medio</th>
                <th>Ultimo Rilevamento</th>
            </tr>
            <?php foreach ($top_domains as $idx => $domain): ?>
            <tr>
                <td><?= $idx + 1 ?></td>
                <td><strong><?= htmlspecialchars($domain['domain']) ?></strong></td>
                <td><?= $domain['count'] ?></td>
                <td class="<?= $domain['avg_score'] >= 5 ? 'score-high' : ($domain['avg_score'] >= 3 ? 'score-medium' : 'score-low') ?>">
                    <?= number_format($domain['avg_score'], 1) ?>
                </td>
                <td><?= htmlspecialchars($domain['last_seen']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($top_domains)): ?>
            <tr><td colspan="5" style="text-align:center;">Nessun dato disponibile</td></tr>
            <?php endif; ?>
        </table>

        <h2>🔍 Ultimi 20 Messaggi</h2>
        <table>
            <tr>
                <th>Score</th>
                <th>Dominio</th>
                <th>Type</th>
                <th>Status</th>
            </tr>
            <?php foreach ($display_entries as $entry): ?>
            <tr>
                <td class="<?= $entry['score'] >= 5 ? 'score-high' : ($entry['score'] >= 3 ? 'score-medium' : 'score-low') ?>">
                    <?= htmlspecialchars($entry['score']) ?>
                </td>
                <td><?= htmlspecialchars($entry['sender']) ?></td>
                <td><code><?= htmlspecialchars($entry['spam_type']) ?></code></td>
                <td>
                    <span class="spam-badge <?= $entry['is_spam'] ? 'spam-yes' : 'spam-no' ?>">
                        <?= $entry['is_spam'] ? 'SPAM' : 'CLEAN' ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>

        <?php if (!empty($domain_counts)): ?>
        <h2>🍕 Distribuzione per Dominio (Vista Corrente)</h2>
        <div class="chart-container">
            <canvas id="domainChart"></canvas>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Score distribution chart
        new Chart(document.getElementById('scoreChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($senders) ?>,
                datasets: [{
                    label: 'Spam Score',
                    data: <?= json_encode($scores) ?>,
                    backgroundColor: <?= json_encode(array_map(fn($e) => $e['is_spam'] ? '#dc3545' : '#28a745', $display_entries)) ?>,
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: 'y',
                scales: {
                    x: { beginAtZero: true, max: 10 }
                },
                plugins: {
                    legend: { display: false }
                }
            }
        });

        <?php if (!empty($domain_counts)): ?>
        // Domain pie chart
        new Chart(document.getElementById('domainChart'), {
            type: 'pie',
            data: {
                labels: <?= json_encode(array_keys($domain_counts)) ?>,
                datasets: [{
                    data: <?= json_encode(array_values($domain_counts)) ?>,
                    backgroundColor: [
                        '#f87171', '#60a5fa', '#34d399', '#fbbf24', '#a78bfa',
                        '#fb7185', '#f472b6', '#c084fc', '#a3e635', '#38bdf8',
                        '#facc15', '#4ade80', '#818cf8', '#f472b6', '#06b6d4',
                        '#f97316', '#8b5cf6', '#ec4899', '#10b981', '#3b82f6'
                    ],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'right' }
                }
            }
        });
        <?php endif; ?>

        <?php if (!empty($trend_data)): ?>
        // Trend chart
        new Chart(document.getElementById('trendChart'), {
            type: 'line',
            data: {
                labels: <?= json_encode($trend_dates) ?>,
                datasets: [{
                    label: 'Messaggi Spam',
                    data: <?= json_encode($trend_counts) ?>,
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                    tension: 0.4,
                    fill: true,
                    yAxisID: 'y'
                }, {
                    label: 'Score Medio',
                    data: <?= json_encode($trend_scores) ?>,
                    borderColor: '#007bff',
                    backgroundColor: 'rgba(0, 123, 255, 0.1)',
                    tension: 0.4,
                    fill: true,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Numero Messaggi'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        beginAtZero: true,
                        max: 10,
                        title: {
                            display: true,
                            text: 'Score Medio'
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                    }
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>
