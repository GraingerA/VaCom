<?php
/**
 * wurd — het overzicht voor de app: mijn kanalen met ongelezen-tellers, alle
 * collega's, en de tellers waarmee de client pollt. Wordt gebruikt door
 * index.php (eerste keer laden) én api.php (daarna), zodat beide altijd
 * hetzelfde teruggeven.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function wurd_overzicht(PDO $db, array $ik): array {
    $mijnId = (int)$ik['id'];
    $st = $db->prepare(<<<'SQL'
SELECT c.id, c.type, c.name, c.topic, c.dm_key, m.last_read_id,
       (SELECT COUNT(*) FROM messages x WHERE x.channel_id = c.id AND x.id > m.last_read_id AND x.user_id != m.user_id AND x.deleted = 0) AS unread,
       (SELECT MAX(id) FROM messages x WHERE x.channel_id = c.id) AS last_id
FROM channels c JOIN channel_members m ON m.channel_id = c.id
WHERE m.user_id = ? AND c.archived = 0
ORDER BY c.type = 'dm', c.name COLLATE NOCASE
SQL);
    $st->execute([$mijnId]);
    $kanalen = [];
    foreach ($st->fetchAll() as $k) {
        $rij = [
            'id'      => (int)$k['id'],
            'type'    => $k['type'],
            'name'    => $k['name'],
            'topic'   => $k['topic'],
            'unread'  => (int)$k['unread'],
            'last_id' => (int)($k['last_id'] ?? 0),
        ];
        if ($k['type'] === 'dm') {
            [$a, $b] = array_map('intval', explode(':', (string)$k['dm_key']));
            $rij['other_id'] = $a === $mijnId ? $b : $a;
        }
        $kanalen[] = $rij;
    }
    $gebruikers = array_map('wurd_public_user', $db->query('SELECT * FROM users ORDER BY name COLLATE NOCASE')->fetchAll());
    return [
        'me'       => wurd_public_user($ik),
        'channels' => $kanalen,
        'users'    => $gebruikers,
        'state'    => (int)wurd_meta('state'),
        'max_id'   => wurd_max_message_id(),
        'version'  => WURD_VERSION,
    ];
}
