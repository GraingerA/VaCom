<?php
/**
 * VaCom — het overzicht voor de app: mijn kanalen met ongelezen-tellers, alle
 * collega's, en de tellers waarmee de client pollt. Wordt gebruikt door
 * index.php (eerste keer laden) én api.php (daarna), zodat beide altijd
 * hetzelfde teruggeven.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function vacom_overzicht(PDO $db, array $ik): array {
    $mijnId = (int)$ik['id'];
    // v0.4.1: gesloten (gearchiveerde) kanalen gaan óók mee, met archived = true.
    // De client zet ze onder "Gesloten kanalen" en opent ze alleen-lezen — zoals
    // in Slack. Ze tellen niet mee als ongelezen.
    // v0.7: 'mentions' = ongelezen berichten waarin ík met @Naam genoemd word.
    $st = $db->prepare(<<<'SQL'
SELECT c.id, c.type, c.name, c.topic, c.dm_key, c.project_token, c.archived, c.created_by, m.last_read_id,
       (SELECT COUNT(*) FROM messages x WHERE x.channel_id = c.id AND x.id > m.last_read_id AND x.user_id != m.user_id AND x.deleted = 0) AS unread,
       (SELECT COUNT(*) FROM mentions mn JOIN messages x ON x.id = mn.message_id
          WHERE x.channel_id = c.id AND mn.user_id = ? AND x.id > m.last_read_id AND x.deleted = 0) AS mentions,
       (SELECT MAX(id) FROM messages x WHERE x.channel_id = c.id) AS last_id
FROM channels c JOIN channel_members m ON m.channel_id = c.id
WHERE m.user_id = ? AND NOT (c.type = 'dm' AND m.hidden = 1) -- v0.5.1: verborgen gesprekken niet in de lijst
ORDER BY c.type = 'dm', c.name COLLATE NOCASE
SQL);
    $st->execute([$mijnId, $mijnId]);
    $kanalen = [];
    foreach ($st->fetchAll() as $k) {
        $gesloten = (int)$k['archived'] === 1;
        $rij = [
            'id'            => (int)$k['id'],
            'type'          => $k['type'],
            'name'          => $k['name'],
            'topic'         => $k['topic'],
            'project_token' => $k['project_token'] ?: null,
            'archived'      => $gesloten,
            'created_by'    => (int)$k['created_by'],
            'unread'        => $gesloten ? 0 : (int)$k['unread'],
            'mentions'      => $gesloten ? 0 : (int)$k['mentions'],
            'last_id'       => (int)($k['last_id'] ?? 0),
            'last_read_id'  => (int)$k['last_read_id'], // v0.7.1: startpunt "Nieuwe berichten" bij openen
        ];
        if ($k['type'] === 'dm') {
            [$a, $b] = array_map('intval', explode(':', (string)$k['dm_key']));
            $rij['other_id'] = $a === $mijnId ? $b : $a;
        }
        $kanalen[] = $rij;
    }
    $gebruikers = array_map('vacom_public_user', $db->query('SELECT * FROM users ORDER BY name COLLATE NOCASE')->fetchAll());
    return [
        'me'         => vacom_public_user($ik) + ['push_alles' => (int)($ik['push_alles'] ?? 0) === 1], // v0.9: push-voorkeur (alleen voor jezelf)
        'channels'   => $kanalen,
        'users'      => $gebruikers,
        'state'      => (int)vacom_meta('state'),
        'max_id'     => vacom_max_message_id(),
        'version'    => VACOM_VERSION,
        'portal'     => VACOM_PORTAL,
        'portal_url' => VACOM_PORTAL ? VACOM_PORTAL_URL : '',
    ];
}
