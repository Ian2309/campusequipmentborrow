<?php
require_once __DIR__ . '/../config/bootstrap.php';
// Rule-based NLP assistant for students and PEMU admins. Runs entirely on your server, no external service.
// Pipeline: tokenise -> stem + synonyms -> typo-tolerant keyword scoring -> entity extraction (equipment, category,
// students, dates) -> intent handler that queries the live database -> follow-up memory + compound questions.

const NLP_STOP = ['the','a','an','is','are','was','were','do','does','did','i','we','you','me','my','of','for','to','in','on','at','and','or',
    'any','there','that','this','it','can','could','would','please','pls','show','tell','give','list','get','want','need','how','what','which',
    'who','when','where','about','with','be','have','has','available','many','much','some','now','today','tomorrow','week','still','yet','been','should'];
const NLP_SYNONYMS = ['computer' => 'laptop', 'notebook' => 'laptop', 'pc' => 'laptop', 'beamer' => 'projector', 'mic' => 'microphone', 'cam' => 'camera'];

// intent => [roles, [keyword => weight], optional fixed answer]. Multi-word keywords match as phrases. Each stem counts once.
const NLP_INTENTS = [
    'greet'   => [['admin', 'student'], ['hi' => 3, 'hello' => 3, 'hey' => 3, 'kumusta' => 3, 'good morning' => 3, 'good afternoon' => 3, 'good evening' => 3]],
    'thanks'  => [['admin', 'student'], ['thanks' => 4, 'thank' => 4, 'salamat' => 4, 'appreciate' => 3]],
    'bye'     => [['admin', 'student'], ['bye' => 4, 'goodbye' => 4, 'see you' => 4, 'paalam' => 4], 'Goodbye! I will be right here if you need anything.'],
    'smalltalk' => [['admin', 'student'], ['how are you' => 7, 'kumusta ka' => 7, 'hows it going' => 6], "I'm doing great, thanks for asking! What can I help you with?"],
    'about'   => [['admin', 'student'], ['who are you' => 7, 'what are you' => 6, 'your name' => 6, 'are you ai' => 6, 'are you a bot' => 6, 'are you real' => 5, 'chatbot' => 3, 'assistant' => 2],
                  "I'm the built-in assistant of the RTU Equipment Hub. I read live data from the system (equipment, requests, due dates) and explain how things work. I'm not a person or a large AI model, so very unusual phrasing may confuse me. Just rephrase it or pick one of the suggestions."],
    'help'    => [['admin', 'student'], ['help' => 4, 'what can you do' => 6, 'commands' => 3, 'menu' => 3, 'options' => 2, 'what can i ask' => 6]],
    'howto'   => [['admin', 'student'], ['how' => 2, 'how do i' => 3, 'how to' => 3, 'steps' => 2, 'guide' => 2, 'tutorial' => 3, 'explain' => 2, 'teach' => 2, 'where' => 1, 'can i' => 1, 'paano' => 3]],
    'account' => [['admin', 'student'], ['who am i' => 7, 'my account' => 5, 'my profile' => 5, 'my details' => 5, 'my student number' => 7, 'my name' => 3, 'my username' => 6]],
    'notifs'  => [['admin', 'student'], ['notification' => 4, 'alert' => 3, 'unread' => 4, 'new messages' => 4, 'any news' => 3]],
    'faq_duration' => [['admin', 'student'], ['how long' => 5, 'how many days' => 6, 'maximum' => 3, 'borrow period' => 6, 'duration' => 3, 'long can' => 5, 'longest' => 4, 'max days' => 6],
                  'Loans can be up to 14 days (the return date can be at most 14 days after the borrow date, and the borrow date cannot be in the past).'],
    'faq_limit' => [['admin', 'student'], ['how many items' => 6, 'limit' => 3, 'maximum items' => 6, 'at a time' => 4, 'how many can' => 5, 'one item' => 2],
                  'There is no overall cap, but each student can have only one active (pending or approved) request per item at a time, and an item that is out of stock cannot be requested.'],
    'faq_refresh' => [['admin', 'student'], ['refresh' => 4, 'real time' => 5, 'update automatically' => 6, 'how often' => 4, 'live update' => 5],
                  'Pages update on their own every few seconds, so new equipment, stock changes, requests and notifications show up without reloading.'],
    'faq_pemu' => [['admin', 'student'], ['what is pemu' => 8, 'who is pemu' => 8, 'meaning of pemu' => 8, 'pemu' => 2],
                  'PEMU is the office that manages and approves equipment borrowing in this system.'],
    'faq_logout' => [['admin', 'student'], ['log out' => 6, 'logout' => 6, 'sign out' => 6], 'Click Log out in the sidebar (or on the Account page) and confirm. Your session also ends automatically after 30 minutes of inactivity.'],

    // students
    'stock'   => [['admin', 'student'], ['meron' => 2, 'mayroon' => 2, 'ilan' => 3, 'natitira' => 3, 'stock' => 3, 'available' => 3, 'availability' => 3, 'how many' => 3, 'units' => 2, 'inventory' => 2, 'left' => 2, 'remaining' => 2, 'quantity' => 2, 'do we have' => 3, 'do you have' => 3, 'have' => 1, 'free' => 1]],
    'catalog' => [['student'], ['what can i borrow' => 6, 'what do you have' => 5, 'categories' => 3, 'catalog' => 3, 'items' => 2, 'equipment' => 1, 'borrowable' => 4]],
    'my_requests' => [['student'], ['my request' => 5, 'my borrow' => 4, 'request status' => 5, 'status' => 2, 'history' => 2, 'approved' => 2, 'pending' => 2, 'rejected' => 2, 'decision' => 2]],
    'my_due'  => [['student'], ['due' => 3, 'deadline' => 3, 'return date' => 5, 'return today' => 7, 'should i return' => 7, 'need to return' => 7, 'to return' => 4, 'overdue' => 3, 'my loan' => 4, 'give back' => 3, 'return' => 2, 'late' => 2, 'ibalik' => 4]],
    'my_damage' => [['student'], ['my damage' => 7, 'damage record' => 6, 'do i owe' => 6, 'owe' => 3, 'i damaged' => 5, 'unsettled' => 3]],
    'waiver'  => [['student'], ['waiver' => 5, 'liability' => 4, 'damage' => 3, 'damaged' => 3, 'broken' => 3, 'lost' => 3, 'responsible' => 2, 'policy' => 2, 'rules' => 2, 'penalty' => 3]],
    'password' => [['student'], ['password' => 4, 'forgot' => 3, 'reset' => 3, 'change password' => 5, 'locked' => 2, 'cant login' => 5, 'cant log in' => 5]],
    'faq_late' => [['student'], ['what happens if' => 3, 'return late' => 7, 'returned late' => 7, 'penalty' => 4, 'late fee' => 6, 'suspension' => 4, 'forget to return' => 7, 'didnt return' => 6, 'forgot to return' => 7, 'cant return' => 5],
                  'Please return items on or before the return date. Under the waiver, overdue items or unsettled damage can lead to suspension of your borrowing privileges. Ask me "What should I return today?" any time to check.'],
    'faq_claim' => [['student'], ['where do i get' => 7, 'where to get' => 7, 'claim' => 4, 'pick up' => 5, 'pickup' => 5, 'collect' => 4, 'after approval' => 6, 'what happens after' => 6, 'what next' => 3, 'where do i return' => 7, 'where to return' => 7],
                  'After PEMU approves your request you get a notification. Claim the item at the PEMU office and bring it back there on or before the return date.'],
    'faq_rejected' => [['student'], ['why was my request' => 7, 'rejected' => 3, 'rejection' => 4, 'declined' => 4, 'denied' => 4, 'why rejected' => 7, 'why declined' => 7, 'not approved' => 5, 'why not approved' => 8],
                  'Open My requests and read the PEMU note next to the request (you also got a notification). You can send a new request with different dates, or choose another item. For more details, ask at the PEMU office.'],
    'faq_cantrequest' => [['student'], ['cant request' => 7, 'cannot request' => 7, 'button disabled' => 7, 'disabled' => 3, 'why cant i' => 7, 'unable to request' => 7, 'request failed' => 6, 'cant borrow' => 7, 'cannot borrow' => 7, 'wont let me' => 7],
                  "A request can fail when: the item is out of stock (the button is disabled); you already have a pending or approved request for that item; the borrow date is in the past or the loan is longer than 14 days; the purpose or course code is missing; or the signature does not exactly match your registered full name."],
    'faq_cancel' => [['student'], ['cancel' => 6, 'can i cancel' => 8, 'cancel my' => 8, 'withdraw' => 4, 'undo request' => 7, 'delete request' => 7, 'change my request' => 6, 'edit my request' => 7, 'modify request' => 7],
                  'Cancelling or editing a request is not available in the app yet. Please tell PEMU at the office and they can reject the pending request so you can submit a new one.'],
    'faq_contact' => [['student'], ['contact' => 4, 'talk to' => 4, 'human' => 3, 'staff' => 3, 'who can i ask' => 7, 'complaint' => 3, 'report a problem' => 6, 'not working' => 3, 'bug' => 3],
                  'For anything I cannot answer, please visit the PEMU office. If something in the app looks broken, tell them what you clicked and what message you saw.'],

    // admins
    'overview'  => [['admin'], ['overview' => 3, 'summary' => 3, 'dashboard' => 3, 'report' => 2, 'stats' => 3, 'statistics' => 3, 'status' => 1, 'how are things' => 5, 'whats happening' => 5, 'update' => 1]],
    'today'     => [['admin'], ['requests today' => 7, 'activity today' => 7, 'what happened today' => 8, 'today summary' => 7, 'daily' => 3, 'done today' => 6]],
    'pending'   => [['admin'], ['pending' => 3, 'waiting' => 2, 'approval' => 3, 'approve' => 2, 'review' => 2, 'new request' => 3, 'request' => 1, 'to approve' => 3]],
    'borrowed'  => [['admin'], ['sino' => 2, 'hawak' => 3, 'hiniram' => 3, 'borrowed' => 3, 'borrower' => 2, 'holding' => 2, 'taken' => 2, 'loan' => 2, 'who' => 2, 'currently' => 1, 'out' => 1, 'active' => 1]],
    'demand'    => [['admin'], ['how many students' => 3, 'want' => 3, 'wish' => 2, 'planning' => 2, 'interested' => 3, 'students' => 1, 'waiting for' => 3]],
    'users'     => [['admin'], ['registered' => 4, 'enrolled' => 4, 'signed up' => 4, 'total students' => 7, 'how many students' => 3, 'accounts' => 3, 'new students' => 5]],
    'top_students' => [['admin'], ['top students' => 6, 'most active' => 5, 'frequent borrowers' => 6, 'borrowed the most' => 7, 'borrows the most' => 7, 'top borrower' => 6, 'top borrowers' => 6]],
    'overdue'   => [['admin'], ['overdue' => 5, 'late' => 3, 'not returned' => 4, 'past due' => 4, 'unreturned' => 4, 'delayed' => 3, 'havent returned' => 5, 'didnt return' => 5, 'huli' => 2, 'hindi pa naibalik' => 5]],
    'due'       => [['admin'], ['due' => 3, 'returning' => 2, 'return' => 1, 'deadline' => 2, 'expected' => 2, 'coming back' => 3, 'to return' => 2]],
    'low_stock' => [['admin'], ['low' => 3, 'low stock' => 5, 'low on stock' => 6, 'running out' => 4, 'out of stock' => 5, 'unavailable' => 3, 'empty' => 3, 'shortage' => 3, 'restock' => 3, 'no stock' => 4, 'run out' => 4]],
    'damage'    => [['admin'], ['damage' => 4, 'damaged' => 4, 'broken' => 3, 'cost' => 2, 'unsettled' => 3, 'repair' => 3, 'faulty' => 3, 'defect' => 2, 'liability' => 1]],
    'student'   => [['admin'], ['student' => 3, 'history' => 3, 'who is' => 3, 'lookup' => 3, 'look up' => 3, 'find' => 1, 'profile' => 2, 'record' => 1, 'details' => 1, 'info' => 1, 'about' => 1]],
    'resets'    => [['admin'], ['password' => 3, 'reset' => 3, 'forgot' => 3, 'locked' => 2]],
    'popular'   => [['admin'], ['popular' => 4, 'most' => 2, 'top' => 3, 'frequent' => 3, 'demand' => 3, 'trending' => 3, 'favorite' => 3, 'borrowed most' => 4]],
    'recent'    => [['admin'], ['recent' => 3, 'latest' => 3, 'newest' => 3, 'activity' => 2, 'last' => 1, 'lately' => 3]],
    'faq_suspend' => [['admin'], ['suspend' => 5, 'block student' => 7, 'disable account' => 7, 'delete student' => 8, 'remove student' => 8, 'deactivate' => 5, 'ban' => 3],
                  'Suspending or deleting students is not in the app yet. In phpMyAdmin open the students table and set the status to Suspended (they cannot log in, and their records are kept). Deleting a student also requires deleting their requests, damage records and reset requests first, because those records reference the student.'],
    'faq_stockrule' => [['admin'], ['how does stock' => 7, 'stock work' => 6, 'when does stock' => 7, 'stock go back' => 7, 'unusable' => 4, 'why did the stock' => 7, 'why is stock' => 6, 'stock decrease' => 7, 'stock change' => 6],
                  'Approving a request reduces the available units by 1. Marking it returned in good condition adds the unit back. If it comes back damaged but repairable, it is also added back; if you tick "unusable", the unit is removed from the total units instead.'],
    'faq_waiver_admin' => [['admin'], ['waiver' => 4, 'signature' => 4, 'signed' => 3, 'agreement' => 3, 'liability' => 2],
                  'Every request stores the waiver version, the typed signature and the time it was signed. You can see it in the Signed waiver column of Borrow requests, and beside each damage record in Damage records.'],
    'faq_edit_equipment' => [['admin'], ['edit equipment' => 8, 'delete equipment' => 8, 'remove equipment' => 8, 'edit item' => 7, 'delete item' => 7, 'change stock' => 7, 'update stock' => 7, 'add stock' => 7, 'add units' => 7, 'remove item' => 7, 'change units' => 7, 'rename' => 4],
                  'Editing or deleting equipment is not in the app yet; the Inventory tab only adds new items. To change a name or the number of units, open the equipment table in phpMyAdmin and edit the row (keep available_units between 0 and total_units). Items that already have requests cannot be deleted.'],
];

// Task topics for "how do I ...?" questions (checked in order; at most two answered together).
const NLP_TOPICS = [
    'admin'   => ['approve' => ['approve', 'accept'], 'reject' => ['reject', 'decline', 'deny'], 'return' => ['return', 'returned'],
                  'add' => ['add', 'create', 'new item', 'new equipment'], 'backup' => ['backup', 'back up', 'restore', 'export'],
                  'adminpw' => ['change password', 'admin password', 'pemu password', 'change my password', 'change the password', 'my password'],
                  'reset' => ['reset', 'forgot'], 'damage' => ['damage', 'damaged', 'broken'], 'notif' => ['notification', 'alert', 'notified'],
                  'student' => ['look up student', 'find student', 'search student', 'student history', 'student record'], 'overdue' => ['overdue', 'late'],
                  'waiver' => ['waiver', 'signature'], 'inventory' => ['inventory', 'check stock', 'view stock'], 'app' => ['app', 'application', 'system', 'website', 'platform', 'get started', 'getting started', 'tour', 'navigate', 'how does this work', 'how it works', 'use this']],
    'student' => ['find' => ['find', 'search', 'look for', 'looking for', 'locate', 'browse', 'filter'], 'borrow' => ['borrow', 'request', 'apply', 'reserve', 'rent'],
                  'return' => ['return', 'give back', 'bring back'], 'track' => ['track', 'status', 'my requests', 'approved'], 'notif' => ['notification', 'alert', 'notified'],
                  'account' => ['account', 'profile', 'student number'], 'password' => ['password', 'forgot', 'reset'], 'waiver' => ['waiver', 'signature', 'liability'],
                  'app' => ['app', 'application', 'system', 'website', 'platform', 'get started', 'getting started', 'tour', 'navigate', 'how does this work', 'how it works', 'use this']],
];

function nlp_tokens(string $text): array {
    $t = preg_replace("/['\x{2018}\x{2019}`]/u", '', mb_strtolower($text));   // can't -> cant, what's -> whats
    $t = preg_replace('/[^\p{L}\p{N}\s-]/u', ' ', $t);
    return array_values(array_filter(preg_split('/\s+/', $t)));
}
function nlp_stem(string $w): string {
    foreach (['ing', 'ed', 'es', 's'] as $suf) {
        if (strlen($w) > 4 && str_ends_with($w, $suf) && strlen($w) - strlen($suf) >= 3) { $w = substr($w, 0, -strlen($suf)); break; }
    }
    return NLP_SYNONYMS[$w] ?? $w;
}
function nlp_same(string $a, string $b): bool {
    if ($a === $b) return true;
    // Typo tolerance: same first letter, one edit (two for long words). Short words must match exactly.
    $n = min(strlen($a), strlen($b));
    return $n >= 5 && $a[0] === $b[0] && levenshtein($a, $b) <= ($n >= 10 ? 2 : 1);
}
function nlp_kw(string $kw, array $norm, string $plain): bool {
    if (str_contains($kw, ' ')) {
        // Phrases match exactly, with plurals/endings stemmed ("damage records" = "damage record"), or run together ("cant login").
        $stemmed = implode(' ', array_map('nlp_stem', explode(' ', $kw)));
        return str_contains($plain, ' ' . $kw . ' ')
            || str_contains(' ' . implode(' ', $norm) . ' ', ' ' . $stemmed . ' ')
            || str_contains($plain, ' ' . preg_replace('/\s+/', '', $kw) . ' ');
    }
    $k = nlp_stem($kw);
    foreach ($norm as $t) if (nlp_same($k, $t)) return true;
    return false;
}
function nlp_has(array $kws, array $norm, string $plain): bool {
    foreach ($kws as $k) if (nlp_kw($k, $norm, $plain)) return true;
    return false;
}
function nlp_topics(string $role, array $norm, string $plain): array {
    $out = [];
    foreach (NLP_TOPICS[$role] as $name => $kws) if (nlp_has($kws, $norm, $plain)) $out[] = $name;
    if (count($out) > 1) $out = array_values(array_diff($out, ['app']));   // a specific task beats the general tour
    return array_slice($out, 0, 2);
}

function nlp_equipment(PDO $pdo, array $norm): array {
    $hits = [];
    foreach ($pdo->query('SELECT id, name, category, total_units AS total, available_units AS available, description FROM equipment')->fetchAll() as $r) {
        $nameToks = array_values(array_filter(array_map('nlp_stem', nlp_tokens($r['name'])), fn($w) => strlen($w) >= 2 && !in_array($w, NLP_STOP, true)));
        if (!$nameToks) continue;
        $m = 0;
        foreach ($nameToks as $nt) foreach ($norm as $qt) if (nlp_same($nt, $qt)) { $m++; break; }
        if ($m >= 1 && $m / count($nameToks) >= 0.5) $hits[] = [$m / count($nameToks) + $m * 0.01, $r];
    }
    usort($hits, fn($a, $b) => $b[0] <=> $a[0]);
    return array_map(fn($h) => $h[1], $hits);
}
function nlp_category(PDO $pdo, array $norm): ?string {
    foreach ($pdo->query('SELECT DISTINCT category FROM equipment')->fetchAll(PDO::FETCH_COLUMN) as $c) {
        foreach ($norm as $t) if (nlp_same(nlp_stem(mb_strtolower($c)), $t) && !in_array($t, NLP_STOP, true)) return $c;
    }
    return null;
}
function nlp_students(PDO $pdo, string $text, array $skip): array {
    $found = [];
    $cols = 'id, student_id, first_name, last_name, course, year_level, status, email, created_at';
    if (preg_match_all('/\b[A-Za-z0-9]+(?:-[A-Za-z0-9]+)+\b/', $text, $m)) {
        foreach ($m[0] as $sid) {
            $s = $pdo->prepare("SELECT $cols FROM students WHERE student_id = ?"); $s->execute([$sid]);
            if ($r = $s->fetch()) $found[$r['id']] = $r;
        }
    }
    if (!$found) {
        $words = array_slice(array_values(array_unique(array_filter(nlp_tokens($text),
            fn($w) => strlen($w) >= 3 && !in_array($w, NLP_STOP, true) && !in_array(nlp_stem($w), $skip, true)))), 0, 4);
        $score = []; $rows = [];
        foreach ($words as $w) {
            $like = '%' . addcslashes($w, '%_\\') . '%';
            $s = $pdo->prepare("SELECT $cols FROM students WHERE first_name LIKE ? OR last_name LIKE ? OR student_id LIKE ? LIMIT 10");
            $s->execute([$like, $like, $like]);
            foreach ($s->fetchAll() as $r) { $rows[$r['id']] = $r; $score[$r['id']] = ($score[$r['id']] ?? 0) + 1; }
        }
        if ($score) { $best = max($score); foreach ($score as $id => $sc) if ($sc === $best) $found[$id] = $rows[$id]; }
    }
    return array_values($found);
}
function nlp_skip_words(): array {
    $s = [];
    foreach (NLP_INTENTS as $def) foreach (array_keys($def[1]) as $k) foreach (explode(' ', $k) as $p) $s[] = nlp_stem($p);
    return array_unique($s);
}

function chat_defaults(string $role): array {
    return $role === 'admin'
        ? ['Give me an overview', 'Any pending requests?', 'Who has not returned items?', 'How do I add equipment?', 'Which items are low on stock?']
        : ['What can I borrow?', 'What should I return today?', 'How do I borrow equipment?', 'How do I use this app?', 'Show my requests'];
}
function chat_d(string $d): string { return date('M j', strtotime($d)); }
function chat_n(PDO $pdo, string $sql, array $args = []): int { $s = $pdo->prepare($sql); $s->execute($args); return (int)$s->fetchColumn(); }
function chat_name(array $r): string { return $r['first_name'] . ' ' . $r['last_name']; }

function chat_example(string $intent, string $role): string {
    return [
        'stock' => 'Is a projector available?', 'catalog' => 'What can I borrow?', 'my_requests' => 'Show my requests', 'my_due' => 'What should I return today?',
        'waiver' => 'What does the waiver say?', 'password' => 'I forgot my password', 'overview' => 'Give me an overview', 'pending' => 'Any pending requests?',
        'borrowed' => 'Who is borrowing items?', 'overdue' => 'Who has not returned items?', 'due' => 'What is due this week?', 'low_stock' => 'Which items are low on stock?',
        'damage' => 'Open damage records', 'student' => 'Show history of student 2021-00123', 'resets' => 'Any password reset requests?',
        'popular' => 'What is borrowed most?', 'recent' => 'Show recent requests', 'howto' => 'How do I borrow equipment?', 'demand' => 'How many students want to borrow a projector?',
        'users' => 'How many students are registered?', 'top_students' => 'Who borrowed the most?', 'today' => 'What happened today?', 'notifs' => 'Do I have notifications?',
        'account' => 'Who am I?', 'my_damage' => 'Do I have damage records?',
    ][$intent] ?? chat_defaults($role)[0];
}

function chat_reply(PDO $pdo, string $role, array $user, string $text): array {
    $role = $role === 'admin' ? 'admin' : 'student';
    $tokens = nlp_tokens($text);
    $norm   = array_map('nlp_stem', $tokens);
    $plain  = ' ' . implode(' ', $tokens) . ' ';
    $ctx    = $_SESSION['chat_ctx'] ?? [];
    if (($ctx['at'] ?? 0) < time() - 600) $ctx = [];
    $reply  = fn(string $t, ?array $sug = null) => ['reply' => $t, 'suggestions' => $sug ?? chat_defaults($role)];

    if (!$tokens) return $reply('Type a question and I will look it up.');

    // Entities: equipment (with synonyms/typos), category, and "it/that" pointing at the last item discussed.
    $equip = nlp_equipment($pdo, $norm);
    $cat   = $equip ? null : nlp_category($pdo, $norm);
    if (!$equip && !$cat && ($ctx['equip'] ?? null) && nlp_has(['it', 'them', 'that', 'this', 'one'], $norm, $plain)) {
        $s = $pdo->prepare('SELECT id, name, category, total_units AS total, available_units AS available, description FROM equipment WHERE id = ?');
        $s->execute([$ctx['equip']]);
        if ($row = $s->fetch()) $equip = [$row];
    }

    // Intent scoring
    $topics = nlp_topics($role, $norm, $plain);
    $scores = [];
    foreach (NLP_INTENTS as $intent => $def) {
        [$roles, $kws] = $def;
        if (!in_array($role, $roles, true)) continue;
        $s = 0; $seen = [];
        foreach ($kws as $kw => $w) {
            $stem = str_contains($kw, ' ') ? $kw : nlp_stem($kw);
            if (isset($seen[$stem])) continue;                 // "borrowed" + "borrowing" must not score twice
            if (nlp_kw($kw, $norm, $plain)) { $s += $w; $seen[$stem] = true; }
        }
        if ($intent === 'howto') $s = ($s > 0 && $topics) ? $s + 2 : 0;
        if ($equip && $s > 0 && in_array($intent, ['stock', 'borrowed'], true)) $s += 2;
        if ($equip && $s > 0 && $intent === 'demand') $s += 4;
        if ($s > 0) $scores[$intent] = $s;
    }
    arsort($scores);
    $intent = $scores ? array_key_first($scores) : null;
    $best   = $scores ? reset($scores) : 0;

    // Follow-ups: bare item names ("what about laptops?"), bare dates ("and tomorrow?"), and task words ("add equipment").
    if (($intent === null || $best < 2) && ($equip || $cat)) {
        $intent = in_array($ctx['intent'] ?? '', ['stock', 'catalog', 'borrowed', 'overdue', 'due', 'pending', 'demand'], true) ? $ctx['intent'] : 'stock';
    }
    if ($intent === null && nlp_has(['today', 'tomorrow', 'week'], $norm, $plain) && in_array($ctx['intent'] ?? '', ['due', 'my_due'], true)) $intent = $ctx['intent'];
    if ($intent === null && ($ctx['intent'] ?? '') === 'student' && $role === 'admin') $intent = 'student';
    if ($intent === null && $topics) $intent = 'howto';

    if ($intent !== null && $best === 1 && !$equip && !$cat && !in_array($intent, ['student', 'howto'], true)) {
        $_SESSION['chat_ctx'] = ['at' => time()];
        return $reply("I'm not completely sure what you mean. Did you want to ask about this?", [chat_example($intent, $role), ...array_slice(chat_defaults($role), 0, 2)]);
    }
    if ($intent === null) {
        return $reply("I didn't quite get that. I can look up " . ($role === 'admin'
            ? 'requests, overdue items, stock, damage records, students and how the admin pages work.' : 'equipment availability, your requests and due dates, and how the app works.') . ' Try one of these:');
    }

    $out = chat_run($pdo, $intent, $role, $user, $equip, $cat, $topics, $norm, $plain, $text);

    // Compound questions ("pending and overdue") answer the top two data intents together.
    $second = array_keys($scores)[1] ?? null;
    $combo  = ['pending', 'overdue', 'low_stock', 'damage', 'resets', 'due', 'borrowed', 'my_requests', 'my_due', 'notifs', 'my_damage'];
    $joined = str_contains($plain, ' and ') || nlp_has(['also', 'tsaka'], $norm, $plain);
    if ($second && $joined && in_array($intent, $combo, true) && in_array($second, $combo, true) && $scores[$second] >= max(3, 0.6 * $best)) {
        $more = chat_run($pdo, $second, $role, $user, $equip, $cat, $topics, $norm, $plain, $text);
        $out[0] .= "\n\n" . $more[0];
    }

    $_SESSION['chat_ctx'] = ['at' => time(), 'intent' => $intent, 'equip' => $equip[0]['id'] ?? ($ctx['equip'] ?? null)];
    return $reply($out[0], $out[1] ?? null);
}

function chat_run(PDO $pdo, string $intent, string $role, array $user, array $equip, ?string $cat, array $topics, array $norm, string $plain, string $text): array {
    $admin = $role === 'admin';
    $me = $admin ? ($user['full_name'] ?: $user['username']) : $user['first_name'];
    if (isset(NLP_INTENTS[$intent][2])) return [NLP_INTENTS[$intent][2]];

    $line = fn(array $r) => '• ' . chat_name($r) . " ({$r['student_id']}): {$r['name']}, " . chat_d($r['borrow_date']) . ' - ' . chat_d($r['return_date']);
    $base = 'SELECT r.id, r.status, r.borrow_date, r.return_date, s.first_name, s.last_name, s.student_id, e.name FROM equipment_requests r JOIN students s ON s.id = r.student_id JOIN equipment e ON e.id = r.equipment_id';
    $ids  = array_map(fn($e) => (int)$e['id'], $equip);
    $eqSql = $ids ? ' AND r.equipment_id IN (' . implode(',', $ids) . ')' : '';   // integers only (cast above)
    $window = nlp_has(['week'], $norm, $plain) ? 7 : (nlp_has(['tomorrow'], $norm, $plain) ? 1 : (nlp_has(['today'], $norm, $plain) ? 0 : null));

    switch ($intent) {
        case 'greet':
            $extra = $admin ? ' There are ' . chat_n($pdo, "SELECT COUNT(*) FROM equipment_requests WHERE status = 'Pending Approval'") . ' request(s) waiting for approval.' : '';
            return ["Hi $me! How can I help?$extra"];
        case 'thanks': return ["You're welcome, $me! Ask me anything else."];
        case 'help':
            return [$admin
                ? "I can answer questions like:\n• Overview, today's activity, pending requests\n• Who is borrowing an item, overdue and due-soon items\n• How many students want to borrow an item, or are registered\n• Stock levels and low stock, most requested items\n• Open damage records and a student's history (use a student number or name)\n• Password reset requests and notifications\n• How to: approve, reject, return, add equipment, back up, change your password, use the app"
                : "I can answer questions like:\n• Is a projector / laptop available? What can I borrow?\n• What should I return today? Show my requests\n• How do I find or borrow equipment? How do I use this app?\n• How long can I borrow? What happens if I am late?\n• What does the waiver say? I forgot my password"];
        case 'howto': return chat_howto($role, $topics);

        case 'account':
            return [$admin ? "You're logged in as {$user['full_name']} (username: {$user['username']}), PEMU administrator."
                : "You're {$user['first_name']} {$user['last_name']}, student number {$user['student_id']}, {$user['course']} {$user['year_level']}. Your email is {$user['email']}."];

        case 'notifs':
            if ($admin) { $w = "recipient_type = 'admin'"; $a = []; } else { $w = "recipient_type = 'student' AND recipient_id = ?"; $a = [(int)$user['id']]; }
            $unread = chat_n($pdo, "SELECT COUNT(*) FROM notifications WHERE $w AND is_read = 0", $a);
            $s = $pdo->prepare("SELECT message FROM notifications WHERE $w ORDER BY id DESC LIMIT 3"); $s->execute($a);
            $rows = $s->fetchAll(PDO::FETCH_COLUMN);
            if (!$rows) return ['You have no notifications yet.'];
            return ["You have $unread unread notification(s). Latest:\n" . implode("\n", array_map(fn($m) => "• $m", $rows))];

        case 'stock':
        case 'catalog':
            $where = ''; $args = [];
            if ($equip) {
                $lines = [];
                foreach (array_slice($equip, 0, 3) as $e) {
                    $lines[] = "{$e['name']} ({$e['category']}): {$e['available']} of {$e['total']} available" . ($e['available'] < 1 ? ' - out of stock' : '');
                    if ($e['description'] && count($equip) === 1) $lines[] = $e['description'];
                }
                return [implode("\n", $lines), $admin ? ["Who has the {$equip[0]['name']}?", "How many students want the {$equip[0]['name']}?", 'Which items are low on stock?'] : ['How do I borrow equipment?', 'What can I borrow?']];
            }
            if ($cat) { $where = 'WHERE category = ?'; $args = [$cat]; }
            elseif ($intent === 'catalog' && !$admin) $where = 'WHERE available_units > 0';
            $s = $pdo->prepare("SELECT name, category, total_units, available_units FROM equipment $where ORDER BY category, name LIMIT 15");
            $s->execute($args);
            $rows = $s->fetchAll();
            if (!$rows) return ['There is no equipment to show yet.'];
            $head = $cat ? "$cat equipment:" : ($intent === 'catalog' && !$admin ? 'Available to borrow right now:' : 'Inventory:');
            return [$head . "\n" . implode("\n", array_map(fn($r) => "• {$r['name']}: {$r['available_units']} of {$r['total_units']} available", $rows))];

        case 'my_requests':
            $s = $pdo->prepare('SELECT r.status, r.borrow_date, r.return_date, r.admin_note, e.name FROM equipment_requests r JOIN equipment e ON e.id = r.equipment_id WHERE r.student_id = ? ORDER BY r.created_at DESC LIMIT 6');
            $s->execute([$user['id']]);
            $rows = $s->fetchAll();
            if (!$rows) return ['You have no requests yet. Pick an item in the Equipment catalog and click Request to borrow.', ['How do I borrow equipment?', 'What can I borrow?']];
            return ["Your latest requests:\n" . implode("\n", array_map(fn($r) => "• {$r['name']} ({$r['status']}), " . chat_d($r['borrow_date']) . ' - ' . chat_d($r['return_date']) . ($r['admin_note'] ? " - PEMU: {$r['admin_note']}" : ''), $rows)), ['What should I return today?', 'What can I borrow?']];

        case 'my_due':
            $s = $pdo->prepare("SELECT r.return_date, e.name FROM equipment_requests r JOIN equipment e ON e.id = r.equipment_id WHERE r.student_id = ? AND r.status = 'Approved' ORDER BY r.return_date");
            $s->execute([$user['id']]);
            $rows = $s->fetchAll();
            if (!$rows) return ['You have no approved items to return right now.'];
            $limit = $window === null ? '9999-12-31' : date('Y-m-d', strtotime("+$window day"));
            $label = $window === 0 ? 'today' : ($window === 1 ? 'by tomorrow' : ($window === 7 ? 'this week' : null));
            $due = array_values(array_filter($rows, fn($r) => $r['return_date'] <= $limit));
            if (!$due) return ["You have nothing to return $label. Your next return is " . $rows[0]['name'] . ' on ' . chat_d($rows[0]['return_date']) . '.'];
            return [($label ? "Return $label:" : 'Items you need to return:') . "\n" . implode("\n", array_map(fn($r) => "• {$r['name']}: " . ($r['return_date'] < date('Y-m-d') ? 'was due ' . chat_d($r['return_date']) . ' (OVERDUE, please return it to PEMU now)' : 'due ' . chat_d($r['return_date']) . ($r['return_date'] === date('Y-m-d') ? ' (today)' : '')), $due))];

        case 'my_damage':
            $s = $pdo->prepare('SELECT d.severity, d.estimated_cost, d.resolved, e.name FROM damage_reports d JOIN equipment e ON e.id = d.equipment_id WHERE d.student_id = ? ORDER BY d.created_at DESC LIMIT 5');
            $s->execute([$user['id']]);
            $rows = $s->fetchAll();
            if (!$rows) return ['You have no damage records. Thank you for taking care of the equipment!'];
            return ["Your damage records:\n" . implode("\n", array_map(fn($r) => "• {$r['name']} ({$r['severity']}), ₱" . number_format($r['estimated_cost'], 2) . ($r['resolved'] ? ' - settled' : ' - NOT yet settled, please see PEMU'), $rows))];

        case 'waiver':
            return ["By signing the waiver you agree to: use equipment only for your stated purpose; return it complete and on time; and repair or replace it at your own cost if it is damaged or lost through your use. Damage is recorded in your name, and unsettled records or overdue items can lead to suspension of borrowing privileges. You sign it by typing your full name when you request an item.", ['How do I borrow equipment?', 'What can I borrow?']];

        case 'password':
            return ["To change your password: open Account details and click Change password (8+ characters with letters and numbers).\nIf you forgot it: on the login page click Forgot password?, enter your student number and registered email, and PEMU will give you a temporary password."];

        case 'overview':
            $units = $pdo->query('SELECT COALESCE(SUM(available_units),0) a, COALESCE(SUM(total_units),0) t FROM equipment')->fetch();
            $t = "Here's where things stand:\n• Pending requests: " . chat_n($pdo, "SELECT COUNT(*) FROM equipment_requests WHERE status = 'Pending Approval'")
                . "\n• Items currently borrowed: " . chat_n($pdo, "SELECT COUNT(*) FROM equipment_requests WHERE status = 'Approved'")
                . "\n• Overdue: " . chat_n($pdo, "SELECT COUNT(*) FROM equipment_requests WHERE status = 'Approved' AND return_date < CURDATE()")
                . "\n• Units on the shelf: {$units['a']} of {$units['t']}"
                . "\n• Low or out-of-stock items: " . chat_n($pdo, 'SELECT COUNT(*) FROM equipment WHERE available_units <= 1 OR available_units * 4 <= total_units')
                . "\n• Unsettled damage records: " . chat_n($pdo, 'SELECT COUNT(*) FROM damage_reports WHERE resolved = 0')
                . "\n• Password reset requests waiting: " . chat_n($pdo, "SELECT COUNT(*) FROM password_resets WHERE status = 'Pending'");
            return [$t, ['Any pending requests?', 'Who has not returned items?']];

        case 'today':
            return ["Today so far:\n• New requests: " . chat_n($pdo, 'SELECT COUNT(*) FROM equipment_requests WHERE DATE(created_at) = CURDATE()')
                . "\n• Decisions made (approved/rejected): " . chat_n($pdo, 'SELECT COUNT(*) FROM equipment_requests WHERE DATE(processed_at) = CURDATE()')
                . "\n• Items returned: " . chat_n($pdo, 'SELECT COUNT(*) FROM equipment_requests WHERE DATE(returned_at) = CURDATE()')
                . "\n• Returns due today: " . chat_n($pdo, "SELECT COUNT(*) FROM equipment_requests WHERE status = 'Approved' AND return_date = CURDATE()")];

        case 'pending':
            $rows = $pdo->query("$base WHERE r.status = 'Pending Approval'$eqSql ORDER BY r.created_at LIMIT 8")->fetchAll();
            if (!$rows) return ['Nothing is waiting for approval.' . ($equip ? " (for {$equip[0]['name']})" : '')];
            return [count($rows) . " request(s) waiting for approval:\n" . implode("\n", array_map($line, $rows)) . "\nOpen Borrow requests to approve or reject them."];

        case 'borrowed':
            $rows = $pdo->query("$base WHERE r.status = 'Approved'$eqSql ORDER BY r.return_date LIMIT 10")->fetchAll();
            if (!$rows) return [$equip ? "No one is borrowing {$equip[0]['name']} right now." : 'No items are currently borrowed.'];
            return ["Currently borrowed" . ($equip ? " ({$equip[0]['name']})" : '') . ":\n" . implode("\n", array_map(fn($r) => $line($r) . ($r['return_date'] < date('Y-m-d') ? ' [OVERDUE]' : ''), $rows))];

        case 'demand':
            if (!$equip) {
                $p = chat_n($pdo, "SELECT COUNT(DISTINCT student_id) FROM equipment_requests WHERE status = 'Pending Approval'");
                $b = chat_n($pdo, "SELECT COUNT(DISTINCT student_id) FROM equipment_requests WHERE status = 'Approved'");
                return ["$p student(s) are waiting for approval and $b student(s) are currently borrowing something. Name an item (for example \"How many students want to borrow a projector?\") for a per-item breakdown."];
            }
            $lines = [];
            foreach (array_slice($equip, 0, 2) as $e) {
                $q = fn(string $w) => chat_n($pdo, "SELECT COUNT(DISTINCT student_id) FROM equipment_requests WHERE equipment_id = ? $w", [$e['id']]);
                $lines[] = "{$e['name']}: " . $q("AND status = 'Pending Approval'") . ' student(s) waiting for approval, ' . $q("AND status = 'Approved'") . ' currently borrowing, ' . $q('') . " borrowed or requested it in total. Stock: {$e['available']} of {$e['total']} available.";
                $s = $pdo->prepare("SELECT s.first_name, s.last_name, s.student_id FROM equipment_requests r JOIN students s ON s.id = r.student_id WHERE r.equipment_id = ? AND r.status = 'Pending Approval' ORDER BY r.created_at LIMIT 5");
                $s->execute([$e['id']]);
                foreach ($s->fetchAll() as $r) $lines[] = '   • waiting: ' . chat_name($r) . " ({$r['student_id']})";
            }
            return [implode("\n", $lines)];

        case 'users':
            return [chat_n($pdo, 'SELECT COUNT(*) FROM students') . ' students are registered: ' . chat_n($pdo, "SELECT COUNT(*) FROM students WHERE status = 'Active'") . ' active, '
                . chat_n($pdo, "SELECT COUNT(*) FROM students WHERE status = 'Suspended'") . ' suspended. ' . chat_n($pdo, 'SELECT COUNT(*) FROM students WHERE created_at >= CURDATE() - INTERVAL 7 DAY') . ' joined in the last 7 days.'];

        case 'top_students':
            $rows = $pdo->query('SELECT s.first_name, s.last_name, s.student_id, COUNT(*) AS c FROM equipment_requests r JOIN students s ON s.id = r.student_id GROUP BY s.id, s.first_name, s.last_name, s.student_id ORDER BY c DESC, s.last_name LIMIT 5')->fetchAll();
            if (!$rows) return ['There are no requests yet.'];
            return ["Most active borrowers:\n" . implode("\n", array_map(fn($r) => '• ' . chat_name($r) . " ({$r['student_id']}): {$r['c']} request(s)", $rows))];

        case 'overdue':
            $rows = $pdo->query("SELECT r.return_date, DATEDIFF(CURDATE(), r.return_date) AS days, s.first_name, s.last_name, s.student_id, e.name, r.borrow_date FROM equipment_requests r JOIN students s ON s.id = r.student_id JOIN equipment e ON e.id = r.equipment_id WHERE r.status = 'Approved' AND r.return_date < CURDATE()$eqSql ORDER BY r.return_date LIMIT 10")->fetchAll();
            if (!$rows) return ['Good news: nothing is overdue.'];
            return [count($rows) . " overdue item(s):\n" . implode("\n", array_map(fn($r) => '• ' . chat_name($r) . " ({$r['student_id']}): {$r['name']}, due " . chat_d($r['return_date']) . " ({$r['days']} day(s) late)", $rows))];

        case 'due':
            $days = $window ?? 7;
            $label = $days === 0 ? 'today' : ($days === 1 ? 'by tomorrow' : 'in the next 7 days');
            $rows = $pdo->query("$base WHERE r.status = 'Approved' AND r.return_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL $days DAY)$eqSql ORDER BY r.return_date LIMIT 10")->fetchAll();
            if (!$rows) return ["No returns are due $label."];
            return ["Due $label:\n" . implode("\n", array_map(fn($r) => '• ' . chat_name($r) . " ({$r['student_id']}): {$r['name']}, due " . chat_d($r['return_date']), $rows))];

        case 'low_stock':
            $rows = $pdo->query('SELECT name, category, total_units, available_units FROM equipment WHERE available_units <= 1 OR available_units * 4 <= total_units ORDER BY available_units, name LIMIT 12')->fetchAll();
            if (!$rows) return ['All items have healthy stock.'];
            return ["Low stock:\n" . implode("\n", array_map(fn($r) => "• {$r['name']}: {$r['available_units']} of {$r['total_units']}" . ($r['available_units'] < 1 ? ' (OUT OF STOCK)' : ''), $rows))];

        case 'damage':
            $rows = $pdo->query('SELECT d.severity, d.estimated_cost, d.created_at, e.name, s.first_name, s.last_name, s.student_id FROM damage_reports d JOIN equipment e ON e.id = d.equipment_id JOIN students s ON s.id = d.student_id WHERE d.resolved = 0 ORDER BY d.created_at DESC LIMIT 8')->fetchAll();
            if (!$rows) return ['There are no unsettled damage records.'];
            $tot = (float)$pdo->query('SELECT COALESCE(SUM(estimated_cost),0) FROM damage_reports WHERE resolved = 0')->fetchColumn();
            return [count($rows) . " unsettled damage record(s), total estimated cost ₱" . number_format($tot, 2) . ":\n" . implode("\n", array_map(fn($r) => '• ' . chat_name($r) . " ({$r['student_id']}): {$r['name']}, {$r['severity']}, ₱" . number_format($r['estimated_cost'], 2), $rows)) . "\nSee the Damage records tab for the signed waivers."];

        case 'student':
            $found = nlp_students($pdo, $text, nlp_skip_words());
            if (!$found) return ['Which student? Give me a student number (like 2021-00123) or a name I can search for.'];
            if (count($found) > 1) return ["I found several matches:\n" . implode("\n", array_map(fn($r) => '• ' . chat_name($r) . " ({$r['student_id']})", array_slice($found, 0, 6))) . "\nAsk again with the student number."];
            $st = $found[0];
            $c = fn(string $w) => chat_n($pdo, "SELECT COUNT(*) FROM equipment_requests WHERE student_id = ? AND $w", [$st['id']]);
            $s = $pdo->prepare('SELECT r.status, r.borrow_date, r.return_date, e.name FROM equipment_requests r JOIN equipment e ON e.id = r.equipment_id WHERE r.student_id = ? ORDER BY r.created_at DESC LIMIT 5');
            $s->execute([$st['id']]);
            $recent = array_map(fn($r) => "• {$r['name']} ({$r['status']}), " . chat_d($r['borrow_date']) . ' - ' . chat_d($r['return_date']), $s->fetchAll());
            return [chat_name($st) . " ({$st['student_id']})\n{$st['course']}, {$st['year_level']} · {$st['status']} · {$st['email']}\n"
                . 'Requests: ' . $c('1=1') . ' total, ' . $c("status = 'Approved'") . ' currently borrowed, '
                . chat_n($pdo, 'SELECT COUNT(*) FROM damage_reports WHERE student_id = ? AND resolved = 0', [$st['id']]) . ' unsettled damage record(s).'
                . ($recent ? "\nRecent:\n" . implode("\n", $recent) : '')];

        case 'resets':
            $rows = $pdo->query("SELECT s.first_name, s.last_name, s.student_id, pr.created_at FROM password_resets pr JOIN students s ON s.id = pr.student_id WHERE pr.status = 'Pending' ORDER BY pr.created_at LIMIT 8")->fetchAll();
            if (!$rows) return ['No password reset requests are waiting.'];
            return [count($rows) . " password reset request(s):\n" . implode("\n", array_map(fn($r) => '• ' . chat_name($r) . " ({$r['student_id']})", $rows)) . "\nOpen the Password resets tab to issue a temporary password."];

        case 'popular':
            $rows = $pdo->query('SELECT e.name, COUNT(*) AS c FROM equipment_requests r JOIN equipment e ON e.id = r.equipment_id GROUP BY e.id, e.name ORDER BY c DESC, e.name LIMIT 5')->fetchAll();
            if (!$rows) return ['There are no requests yet, so no trends to show.'];
            return ["Most requested items:\n" . implode("\n", array_map(fn($r) => "• {$r['name']}: {$r['c']} request(s)", $rows))];

        case 'recent':
            $rows = $pdo->query("$base ORDER BY r.created_at DESC LIMIT 6")->fetchAll();
            if (!$rows) return ['No requests have been made yet.'];
            return ["Latest requests:\n" . implode("\n", array_map(fn($r) => $line($r) . " [{$r['status']}]", $rows))];
    }
    return ["I'm not sure about that one."];
}

function chat_howto(string $role, array $topics): array {
    $a = [
        'app'      => "Quick tour of the admin side:\n• Overview: counts and the requests waiting for you.\n• Borrow requests: every request with its signed waiver; approve, reject or mark returned.\n• Inventory: add equipment and see stock.\n• Damage records: damage reported on return, with the waiver.\n• Password resets: temporary passwords for students.\n• Notifications: alerts when students request items.\n• Account and backup: your details, change password, database backup.",
        'approve'  => 'Open Borrow requests, find the pending request and click Approve. Add an optional note, then Confirm. The item count drops by one and the student gets a notification.',
        'reject'   => 'Open Borrow requests, click Reject on the pending request, add a reason as the note, then Confirm. The student is notified.',
        'return'   => 'Open Borrow requests, click Mark returned on the approved request. Choose good condition, or damaged and describe it. Good returns go back to stock.',
        'add'      => 'Open Inventory, fill in the name, category and total units (image link and description are optional), then click Add to inventory. Students see it within seconds.',
        'backup'   => 'Open Account and backup and click Download backup. A copy is also saved in the server backups folder. To restore, import the .sql file in phpMyAdmin.',
        'adminpw'  => 'Open Account and backup and click Change password. You need your current password, and the new one must be 10+ characters with letters and numbers. If you are locked out, run database\create_admin.php from the command line with your username and a new password.',
        'reset'    => 'Open Password resets, click Generate temporary password for the student, and give it to them privately. They must set a new password at next login.',
        'damage'   => 'When marking an item returned, choose Returned damaged, describe it, set severity and estimated cost. It is saved in Damage records with the signed waiver. Mark it settled once the student pays or replaces it.',
        'notif'    => 'New student requests appear in the Notifications tab and as a pop-up alert within seconds. The bell and tab badge show unread alerts, and Mark all as read clears them.',
        'student'  => 'Ask me, for example, "history of 2021-00123" or "show Maria". You can also search by student, number or equipment in Borrow requests.',
        'overdue'  => 'Ask me "Who has not returned items?". In Borrow requests, approved requests past their return date show a red Overdue tag; filter by Approved to find them.',
        'waiver'   => 'Every request stores the waiver version, the typed signature and the signing time. See it in the Signed waiver column of Borrow requests, and beside each record in Damage records.',
        'inventory' => 'Open Inventory to see every item with available and total units. Ask me "Which items are low on stock?" for a quick check.',
    ];
    $s = [
        'app'      => "Quick tour:\n• Equipment catalog: browse and search items with live availability, then click Request to borrow.\n• My requests: the status of every request.\n• Notifications: confirmations and PEMU decisions (the bell shows unread ones).\n• Account details: your student number and profile, change password, log out.\nThe glowing orb at the bottom left is me. Ask me anything!",
        'find'     => 'Open the Equipment catalog. Type a name in the search box or pick a category to filter. Each card shows how many units are available right now and updates automatically. You can also ask me, for example, "Is a laptop available?"',
        'borrow'   => 'Open the Equipment catalog, click Request to borrow on an item, fill in the purpose, dates and course code, read the waiver, tick the box and type your full name to sign, then submit. PEMU reviews it and you get a notification. After approval, claim the item at the PEMU office.',
        'return'   => 'Bring the item back to the PEMU office on or before the return date; PEMU marks it returned and you get a notification. Ask me "What should I return today?" to see what is due.',
        'track'    => 'Open My requests to see every request and its status: Pending Approval, Approved, Rejected or Returned. You also get a notification on each decision.',
        'notif'    => 'Open Notifications (or click the bell) to read confirmations and decisions. New ones pop up as alerts and the bell shows how many are unread. Use Mark all as read to clear them.',
        'account'  => 'Click your student number at the top right (or Account details) to see your profile, change your password, or log out.',
        'password' => 'Change it from Account details > Change password (8+ characters with letters and numbers). If you forgot it, use Forgot password? on the login page and PEMU will give you a temporary password.',
        'waiver'   => 'The waiver appears in the request form. Tick the agreement and type your full name exactly as registered to sign it.',
    ];
    $set = $role === 'admin' ? $a : $s;
    $keys = array_values(array_filter($topics, fn($t) => isset($set[$t])));
    if ($keys) {
        $more = array_values(array_diff(array_keys($set), $keys));
        return [implode("\n\n", array_map(fn($k) => $set[$k], $keys)), array_map(fn($k) => 'How do I ' . $k . '?', array_slice($more, 0, 4))];
    }
    return ['Which task? I can explain: ' . implode(', ', array_keys($set)) . '.', array_map(fn($k) => 'How do I ' . $k . '?', array_slice(array_keys($set), 0, 4))];
}

// ---------- Endpoint (must stay at the bottom, after the constants and functions above) ----------
[$chatRole, $chatUser] = require_login();            // same helper notifications.php uses: ['admin'|'student', userRow]
$in    = post();
$query = mb_substr(trim((string)($in['query'] ?? '')), 0, 200);

try {
    out(['success' => true] + chat_reply($pdo, $chatRole, $chatUser, $query));
} catch (Throwable $e) {
    error_log('chat.php: ' . $e->getMessage());
    fail('The assistant ran into a problem. Please try again.', 500);
}