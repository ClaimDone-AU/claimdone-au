<?php
/**
 * MCP connector (13 Oct 2026, Ken: "add an mcp connector so peoples ai will look at driving the letters for them ...
 * make sure ai agents can pay us also, make it simple").
 *
 * A remote Model Context Protocol server (Streamable HTTP, stateless JSON-RPC 2.0) at
 *   https://claimdone.com.au/mcp   (also /wp-json/claimdone/v1/mcp)
 * that any assistant with connector support (Claude, ChatGPT, Claude Code, Cursor...) can use to run the whole job:
 * match the problem to a document, start it, answer the questions, get the Stripe payment link, and fetch the
 * draft once it is paid. Everything goes through the same AJAX handlers the website uses (CD_Ajax,
 * CD_Intake_Followup), called in-process with a fresh nonce and the per-case access token, so every rule
 * (rate limits, eligibility notices, stop answers, single checkout per case, delivery flow) applies unchanged.
 *
 * Payment: the agent receives a hosted Stripe Checkout URL and hands it to the person (or pays it itself if it
 * has a browser and a card on file). The person approves the document on their dashboard before anything is
 * sent; the connector never sends a document on anyone's behalf.
 *
 * Discovery: /.well-known/mcp.json. Off switch: option cd_mcp = '0'.
 */
if (!defined('ABSPATH')) exit;

class CD_Mcp_Exit extends \Exception {}

class CD_Mcp {
    const PROTOCOL = '2025-06-18';
    const VERSION  = '1.0.0';
    const STATES   = ['NSW' => 'New South Wales', 'VIC' => 'Victoria', 'QLD' => 'Queensland', 'WA' => 'Western Australia', 'SA' => 'South Australia', 'TAS' => 'Tasmania', 'ACT' => 'Australian Capital Territory', 'NT' => 'Northern Territory'];

    public static function register() {
        add_action('rest_api_init', function () {
            register_rest_route('claimdone/v1', '/mcp', [
                'methods'             => ['POST', 'GET', 'DELETE', 'OPTIONS'],
                'callback'            => [__CLASS__, 'rest'],
                'permission_callback' => '__return_true',
            ]);
        });
        add_action('init', function () {
            add_rewrite_rule('^mcp/?$', 'index.php?cd_mcp=1', 'top');
            add_rewrite_rule('^\.well-known/mcp\.json$', 'index.php?cd_mcp_meta=1', 'top');
            if (get_option('cd_mcp_rewrite', '') !== 'v1') { flush_rewrite_rules(false); update_option('cd_mcp_rewrite', 'v1', false); }
        });
        add_filter('query_vars', function ($v) { $v[] = 'cd_mcp'; $v[] = 'cd_mcp_meta'; return $v; });
        add_action('parse_request', function ($wp) {
            if (!empty($wp->query_vars['cd_mcp'])) { self::serve(); }
            if (!empty($wp->query_vars['cd_mcp_meta'])) { self::serve_meta(); }
        });
    }

    // ------------------------------------------------------------------ transport

    /** REST entry: same handler, WordPress wraps the status and headers. */
    public static function rest(\WP_REST_Request $req) {
        self::serve($req->get_method(), (string) $req->get_body());
        exit;
    }

    private static function cors() {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization, Mcp-Session-Id, MCP-Protocol-Version');
        header('Access-Control-Expose-Headers: Mcp-Session-Id, MCP-Protocol-Version');
        header('MCP-Protocol-Version: ' . self::PROTOCOL);
    }

    public static function serve($method = null, $body = null) {
        nocache_headers();
        self::cors();
        $method = $method ?: ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'OPTIONS') { status_header(204); exit; }
        if (get_option('cd_mcp', '1') === '0') { self::out(503, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32000, 'message' => 'The Claim Done connector is switched off for maintenance.']]); }
        if ($method === 'GET') { header('Allow: POST'); self::out(405, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32000, 'message' => 'This MCP server is stateless: send JSON-RPC requests by POST. Human? Read https://claimdone.com.au/connect-your-ai-assistant/']]); }
        if ($method === 'DELETE') { status_header(200); exit; }
        if (self::limited('mcp_rpc', 120, MINUTE_IN_SECONDS)) { self::out(429, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32000, 'message' => 'Too many requests from this address. Try again in a minute.']]); }

        $raw = $body !== null ? $body : (string) file_get_contents('php://input');
        $msg = json_decode($raw, true);
        if (!is_array($msg)) { self::out(400, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]); }
        $batch = array_keys($msg) === range(0, count($msg) - 1);
        $responses = [];
        foreach ($batch ? $msg : [$msg] as $m) {
            $r = self::dispatch(is_array($m) ? $m : []);
            if ($r !== null) $responses[] = $r;
        }
        if (!$responses) { status_header(202); exit; } // notifications only
        self::out(200, $batch ? $responses : $responses[0]);
    }

    private static function out($status, $payload) {
        status_header($status);
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** One JSON-RPC message in, one response out (null for notifications). */
    private static function dispatch(array $m) {
        $id     = $m['id'] ?? null;
        $method = (string) ($m['method'] ?? '');
        $params = is_array($m['params'] ?? null) ? $m['params'] : [];
        $isNotification = !array_key_exists('id', $m);
        $ok = function ($result) use ($id) { return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]; };
        $err = function ($code, $message) use ($id) { return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]; };

        switch ($method) {
            case 'initialize':
                return $ok([
                    'protocolVersion' => self::PROTOCOL,
                    'capabilities'    => ['tools' => ['listChanged' => false]],
                    'serverInfo'      => ['name' => 'Claim Done', 'title' => 'Claim Done: Australian legal documents drafted from your answers', 'version' => self::VERSION],
                    'instructions'    => "Claim Done drafts Australian legal documents (letters of demand, tribunal applications, cease and desist letters, notices, agreements) from a person's answers for a flat fee in AUD. Flow: match_problem (optional) -> start_document -> answer_questions until ready_to_pay -> get_payment_link (the person pays on Stripe's hosted page) -> get_status / get_document. Claim Done is not a law firm and does not give legal advice; the person reads and approves every document on their dashboard before anything is sent. Never invent facts: ask the person for each answer.",
                ]);
            case 'notifications/initialized':
            case 'notifications/cancelled':
            case 'notifications/progress':
                return null;
            case 'ping':
                return $ok(new \stdClass());
            case 'tools/list':
                return $ok(['tools' => self::tools()]);
            case 'resources/list':
                return $ok(['resources' => []]);
            case 'prompts/list':
                return $ok(['prompts' => []]);
            case 'tools/call':
                $name = (string) ($params['name'] ?? '');
                $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
                $tools = array_column(self::tools(), 'name');
                if (!in_array($name, $tools, true)) return $err(-32602, 'Unknown tool: ' . $name);
                self::count($name);
                try {
                    $res = call_user_func([__CLASS__, 'tool_' . str_replace('-', '_', $name)], $args);
                } catch (\Throwable $e) {
                    error_log('CD_Mcp tool ' . $name . ' failed: ' . $e->getMessage());
                    $res = ['error' => 'Something went wrong on our side. Please try again, or use https://claimdone.com.au/begin/ directly.'];
                }
                $isError = is_array($res) && !empty($res['error']);
                return $ok([
                    'content'           => [['type' => 'text', 'text' => wp_json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]],
                    'structuredContent' => $res,
                    'isError'           => $isError,
                ]);
            default:
                return $isNotification ? null : $err(-32601, 'Method not found: ' . $method);
        }
    }

    // ------------------------------------------------------------------ tools

    public static function tools() {
        $tools = self::tool_defs();
        // Directory reviews want every tool annotated: read-only or not, destructive or not, open-world or not.
        $ro = ['list_services' => true, 'match_problem' => true, 'start_document' => false, 'answer_questions' => false, 'get_payment_link' => false, 'get_status' => true, 'get_document' => true];
        foreach ($tools as &$t) {
            $t['annotations'] = ['title' => $t['title'], 'readOnlyHint' => !empty($ro[$t['name']]), 'destructiveHint' => false, 'idempotentHint' => $t['name'] !== 'start_document', 'openWorldHint' => false];
        }
        unset($t);
        return $tools;
    }

    private static function tool_defs() {
        $case_props = [
            'case_id'      => ['type' => 'integer', 'description' => 'The case id returned by start_document.'],
            'access_token' => ['type' => 'string', 'description' => 'The access_token returned by start_document.'],
        ];
        return [
            [
                'name'        => 'list_services',
                'title'       => 'List the documents Claim Done drafts',
                'description' => 'Every document Claim Done drafts, with its live flat price in AUD, what the person receives and how it is delivered. Use match_problem instead when the person has described a situation.',
                'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'description' => 'Optional word to filter by, e.g. "tenant", "invoice", "tribunal".']], 'additionalProperties' => false],
            ],
            [
                'name'        => 'match_problem',
                'title'       => 'Match a situation to the right document',
                'description' => 'Give a plain-English description of the person\'s problem (who, what happened, what they want) and get the document that fits, its price, and what it does. Then call start_document.',
                'inputSchema' => ['type' => 'object', 'properties' => ['problem' => ['type' => 'string', 'description' => 'The situation in the person\'s own words, 10 to 500 characters.']], 'required' => ['problem'], 'additionalProperties' => false],
            ],
            [
                'name'        => 'start_document',
                'title'       => 'Start a document',
                'description' => 'Creates the case and returns the questions to ask the person. Nothing is charged at this step. Ask the person for the state they live in and what happened before calling.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'service'   => ['type' => 'string', 'description' => 'Service slug from list_services or match_problem, e.g. "letter-of-demand".'],
                    'state'     => ['type' => 'string', 'enum' => array_keys(self::STATES), 'description' => 'The person\'s Australian state or territory.'],
                    'what_happened' => ['type' => 'string', 'description' => 'What happened, in the person\'s words (2 to 6 sentences).'],
                    'your_name' => ['type' => 'string', 'description' => 'The person\'s full name or business name, as it should appear on the document.'],
                    'your_email' => ['type' => 'string', 'description' => 'The person\'s email address: the draft, the receipt and the dashboard link go there.'],
                ], 'required' => ['service', 'state', 'what_happened', 'your_name', 'your_email'], 'additionalProperties' => false],
            ],
            [
                'name'        => 'answer_questions',
                'title'       => 'Answer the questions for a document',
                'description' => 'Saves answers (question key => answer) for a case. Returns any questions still open, any follow-up questions the drafting needs, and ready_to_pay when the document can be drafted. Only pass facts the person has actually given.',
                'inputSchema' => ['type' => 'object', 'properties' => $case_props + ['answers' => ['type' => 'object', 'description' => 'Question key => the person\'s answer. For a question with choices, pass one choice exactly.', 'additionalProperties' => ['type' => 'string']]], 'required' => ['case_id', 'access_token', 'answers'], 'additionalProperties' => false],
            ],
            [
                'name'        => 'get_payment_link',
                'title'       => 'Get the payment link',
                'description' => 'Returns a Stripe Checkout URL for the case at the live flat price. Give it to the person (or open it yourself if you can pay). The document is drafted automatically once payment goes through, usually within about 5 minutes. If the case carries a notice, read it to the person and call again with acknowledge_notice=true.',
                'inputSchema' => ['type' => 'object', 'properties' => $case_props + [
                    'acknowledge_notice' => ['type' => 'boolean', 'description' => 'Set true after the person has heard the eligibility notice returned by answer_questions or a previous call.'],
                    'registered_post'    => ['type' => 'boolean', 'description' => 'Add registered post delivery of a letter (extra fee, shown in list_services).'],
                ], 'required' => ['case_id', 'access_token'], 'additionalProperties' => false],
            ],
            [
                'name'        => 'get_status',
                'title'       => 'Check a case',
                'description' => 'Whether the case is paid, whether the draft is ready, and the dashboard link where the person reads, edits and approves it.',
                'inputSchema' => ['type' => 'object', 'properties' => $case_props, 'required' => ['case_id', 'access_token'], 'additionalProperties' => false],
            ],
            [
                'name'        => 'get_document',
                'title'       => 'Read the drafted document',
                'description' => 'After payment: the full text of each drafted document so the person can read it, plus the dashboard link where they approve it (and, for letters, have it sent). Documents are never sent without the person\'s approval on the dashboard.',
                'inputSchema' => ['type' => 'object', 'properties' => $case_props, 'required' => ['case_id', 'access_token'], 'additionalProperties' => false],
            ],
        ];
    }

    private static function service_summary($slug, $s) {
        $flow = CD_Case::service_flow($slug);
        $instant = $flow === 'instant_download';
        $review  = function_exists('cd_is_contract_review_service') && cd_is_contract_review_service($slug);
        return [
            'service'   => $slug,
            'name'      => html_entity_decode((string) ($s['name'] ?? $slug), ENT_QUOTES, 'UTF-8'),
            'price_aud' => (int) CD_Case::price($slug),
            'about'     => wp_strip_all_tags(html_entity_decode((string) ($s['desc'] ?? ''), ENT_QUOTES, 'UTF-8')),
            'delivery'  => $review ? 'A plain-English report, emailed as a PDF once the person confirms it.' : ($instant ? 'The person reads and confirms the draft, then receives the final PDF by email to sign, lodge or send themselves.' : 'The person reads and approves the letter on their dashboard, then Claim Done emails it to the other party (registered post is an optional extra) with a copy and confirmation to them.'),
            'page'      => home_url('/begin/?service=' . rawurlencode($slug)),
        ];
    }

    public static function tool_list_services($a) {
        $q = strtolower(trim((string) ($a['query'] ?? '')));
        $out = [];
        foreach (CD_Case::services() as $slug => $s) {
            if (function_exists('cd_is_contract_review_service') && cd_is_contract_review_service($slug) && $slug !== 'contract-review') continue; // the reviewer's contract-type variants are one product
            $row = self::service_summary($slug, $s);
            if ($q !== '' && stripos($row['name'] . ' ' . $row['about'] . ' ' . $slug, $q) === false) continue;
            $out[] = $row;
        }
        return ['count' => count($out), 'currency' => 'AUD', 'note' => 'Flat fees, shown before paying. Registered post for a letter is $' . (class_exists('CD_Post_Addon') ? (int) CD_Post_Addon::PRICE : 29) . ' extra. Claim Done is not a law firm and does not give legal advice.', 'services' => $out];
    }

    public static function tool_match_problem($a) {
        $problem = trim((string) ($a['problem'] ?? ''));
        if (strlen($problem) < 10) return ['error' => 'Describe the problem in at least a sentence.'];
        $r = self::call_ajax([CD_Ajax::instance(), 'handle_problem_search'], ['problem' => $problem]);
        if (empty($r['success'])) return ['error' => is_string($r['data'] ?? null) ? $r['data'] : 'Could not match that problem.'];
        $d = (array) $r['data'];
        if (empty($d['service']) || $d['service'] === 'none') return ['matched' => false, 'reply' => (string) ($d['reply'] ?? 'No single document fits that. Ask the person for more detail: who the other party is and what they did.')];
        $slug = (string) $d['service'];
        $svc  = CD_Case::services()[$slug] ?? null;
        return ['matched' => true, 'reply' => (string) ($d['reply'] ?? ''), 'what_it_does' => array_values((array) ($d['does'] ?? [])), 'recommended' => $svc ? self::service_summary($slug, $svc) : ['service' => $slug], 'next' => 'Confirm with the person, ask for their state, name and email, then call start_document.'];
    }

    /** Questions for a service: the service's own list plus the common ones the website chat asks. */
    private static function questions($slug, array $data = []) {
        $svc = CD_Case::services()[$slug] ?? null;
        $qs  = (class_exists('CD_Intake') && method_exists('CD_Intake', 'intake_questions')) ? (array) (CD_Intake::intake_questions()[$slug] ?? []) : [];
        $out = [];
        foreach ($qs as $q) {
            $k = (string) ($q['key'] ?? '');
            if ($k === '' || in_array($k, ['claimant_state', 'claimant_name', 'claimant_email', 'what_happened'], true)) continue;
            if (!empty($q['when']) && is_array($q['when'])) { // conditional on an earlier answer
                $show = true;
                foreach ($q['when'] as $dep => $re) { if (!isset($data[$dep]) || !preg_match('/' . $re . '/i', (string) $data[$dep])) $show = false; }
                if (!$show) continue;
            }
            $row = ['key' => $k, 'question' => html_entity_decode(wp_strip_all_tags((string) ($q['ask'] ?? $k)), ENT_QUOTES, 'UTF-8'), 'type' => (string) ($q['type'] ?? 'text'), 'required' => empty($q['optional'])];
            if (!empty($q['choices'])) $row['choices'] = array_values(array_map('strval', (array) $q['choices']));
            if (!empty($q['hint'])) $row['hint'] = html_entity_decode(wp_strip_all_tags((string) $q['hint']), ENT_QUOTES, 'UTF-8');
            if (!empty($q['placeholder'])) $row['example'] = (string) $q['placeholder'];
            if (isset($data[$k]) && trim((string) $data[$k]) !== '') $row['answered'] = mb_substr((string) $data[$k], 0, 120);
            $out[] = $row;
        }
        return $out;
    }

    public static function tool_start_document($a) {
        $slug  = sanitize_key((string) ($a['service'] ?? ''));
        $svcs  = CD_Case::services();
        if (!isset($svcs[$slug])) return ['error' => 'Unknown service. Call list_services or match_problem first.'];
        if (function_exists('cd_is_contract_review_service') && cd_is_contract_review_service($slug)) return ['error' => 'The contract review needs the contract file uploaded, which this connector does not do yet. Send the person to ' . home_url('/begin/?service=contract-review')];
        $code  = strtoupper(trim((string) ($a['state'] ?? '')));
        if (!isset(self::STATES[$code])) return ['error' => 'state must be one of ' . implode(', ', array_keys(self::STATES))];
        $story = trim((string) ($a['what_happened'] ?? ''));
        $name  = trim((string) ($a['your_name'] ?? ''));
        $email = sanitize_email((string) ($a['your_email'] ?? ''));
        if (mb_strlen($story) < 15) return ['error' => 'what_happened needs at least a sentence.'];
        if ($name === '') return ['error' => 'your_name is required.'];
        if (!is_email($email)) return ['error' => 'your_email must be a valid email address: the draft and receipt go there.'];
        if (self::limited('mcp_case', 15, HOUR_IN_SECONDS)) return ['error' => 'Too many documents started from this address. Try again in an hour.'];

        $post = ['service_type' => $slug, 'what_happened' => $story, 'claimant_state' => self::STATES[$code], 'claimant_name' => $name, 'claimant_email' => $email, 'chat_mode' => 'mcp'];
        $r = self::call_ajax([CD_Ajax::instance(), 'handle_save_intake'], $post);
        $case_id = (int) ($r['data']['case_id'] ?? 0);
        if (empty($r['success']) || !$case_id) return ['error' => is_string($r['data'] ?? null) ? $r['data'] : (string) ($r['data']['message'] ?? 'Could not start the document.')];
        CD_Case::set_data($case_id, 'traffic_source', 'ai_agent');
        CD_Case::set_data($case_id, 'chat_mode', 'mcp'); // with this set, checkout refuses the case until intake_complete is recorded
        CD_Case::set_data($case_id, 'mcp_client', mb_substr(sanitize_text_field((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 120));
        $data = (array) CD_Case::get_data($case_id);
        return [
            'case_id'      => $case_id,
            'access_token' => CD_Case::access_token($case_id),
            'service'      => $slug,
            'price_aud'    => (int) CD_Case::price($slug),
            'questions'    => self::questions($slug, $data),
            'next'         => 'Ask the person each question (one at a time works best), then call answer_questions with the answers. Required questions must be answered; optional ones may be skipped.',
        ];
    }

    private static function auth(array $a) {
        $case_id = (int) ($a['case_id'] ?? 0);
        $token   = (string) ($a['access_token'] ?? '');
        if (!$case_id || !CD_Case::verify_token($case_id, $token)) return [null, ['error' => 'case_id or access_token is wrong.']];
        $case = CD_Case::get($case_id);
        if (!$case) return [null, ['error' => 'Case not found.']];
        return [$case, null];
    }

    public static function tool_answer_questions($a) {
        list($case, $e) = self::auth($a); if ($e) return $e;
        $case_id = (int) $case->id;
        $answers = is_array($a['answers'] ?? null) ? $a['answers'] : [];
        if ($case->status !== 'pending_payment') return ['error' => 'This case is already paid; answers cannot be changed. Call get_status.'];
        $defs = (array) (CD_Intake::intake_questions()[$case->service_type] ?? []);
        $byKey = [];
        foreach ($defs as $q) if (!empty($q['key'])) $byKey[(string) $q['key']] = $q;
        $post = ['case_id' => $case_id, 't' => CD_Case::access_token($case_id), 'service_type' => $case->service_type];
        $stopped = '';
        $prior = (array) CD_Case::get_data($case_id);
        $fu_text = trim((string) ($prior['intake_followups'] ?? ''));
        foreach ($answers as $k => $v) {
            $k = sanitize_key((string) $k);
            if (!is_scalar($v)) continue;
            $v = trim((string) $v);
            if ($k === '' || $v === '') continue;
            // Answers to the follow-up questions (keys followup_<round>_<n>) go into the intake_followups transcript the drafting reads.
            if (preg_match('/^followup_(\d+)_(\d+)$/', $k, $m)) {
                $qs = json_decode((string) ($prior['followup_round_' . (int) $m[1] . '_questions'] ?? ''), true);
                $q  = is_array($qs) ? ($qs[(int) $m[2] - 1] ?? null) : null;
                $qt = is_array($q) ? (string) ($q['ask'] ?? $q['question'] ?? '') : (string) $q;
                if ($qt !== '') $fu_text .= ($fu_text !== '' ? "\n" : '') . 'Q: ' . mb_substr($qt, 0, 300) . "\nA: " . mb_substr($v, 0, 1500);
                continue;
            }
            if (isset($byKey[$k]['choices']) && !in_array($v, array_map('strval', (array) $byKey[$k]['choices']), true)) {
                // accept a case-insensitive or prefix match of a choice
                $hit = '';
                foreach ((array) $byKey[$k]['choices'] as $c) { if (strcasecmp((string) $c, $v) === 0 || stripos((string) $c, $v) === 0) { $hit = (string) $c; break; } }
                if ($hit === '' && !empty($byKey[$k]['strict'])) return ['error' => 'Answer for ' . $k . ' must be one of: ' . implode(' | ', (array) $byKey[$k]['choices'])];
                if ($hit !== '') $v = $hit;
            }
            if (!empty($byKey[$k]['stop']) && is_array($byKey[$k]['stop']) && isset($byKey[$k]['stop'][$v])) $stopped = (string) $byKey[$k]['stop'][$v];
            $post[$k] = mb_substr($v, 0, 4000);
        }
        if ($stopped !== '') $post['intake_stopped'] = $stopped;
        if ($fu_text !== '' && $fu_text !== trim((string) ($prior['intake_followups'] ?? ''))) $post['intake_followups'] = mb_substr($fu_text, 0, 12000);
        $r = self::call_ajax([CD_Ajax::instance(), 'handle_save_intake'], $post);
        if (empty($r['success'])) return ['error' => is_string($r['data'] ?? null) ? $r['data'] : (string) ($r['data']['message'] ?? 'Could not save the answers.')];
        if ($stopped !== '') return ['stopped' => true, 'message' => $stopped, 'ready_to_pay' => false];

        $data = (array) CD_Case::get_data($case_id);
        $qs   = self::questions($case->service_type, $data);
        $open = array_values(array_filter($qs, function ($q) { return !empty($q['required']) && empty($q['answered']); }));
        if ($open) return ['saved' => true, 'ready_to_pay' => false, 'remaining_questions' => $open, 'optional_questions' => array_values(array_filter($qs, function ($q) { return empty($q['required']) && empty($q['answered']); }))];

        // Follow-up round: the server checks the answers against what the document needs (up to 3 questions, 2 rounds).
        $fu = self::call_ajax([CD_Intake_Followup::instance(), 'ajax_followup'], ['case_id' => $case_id, 't' => CD_Case::access_token($case_id), 'answers' => wp_json_encode(array_intersect_key($data, array_flip(array_merge(array_keys($byKey), ['what_happened', 'claimant_state', 'claimant_name'])))), 'asked' => wp_json_encode(array_fill_keys(array_keys($byKey), true)), 'skipped' => '[]']);
        $fq = array_values((array) ($fu['data']['questions'] ?? []));
        if ($fq) {
            $list = [];
            foreach ($fq as $i => $q) $list[] = ['key' => 'followup_' . ((int) ($fu['data']['round'] ?? 1)) . '_' . ($i + 1), 'question' => is_array($q) ? (string) ($q['ask'] ?? $q['question'] ?? '') : (string) $q, 'type' => 'text', 'required' => false];
            return ['saved' => true, 'ready_to_pay' => false, 'follow_up_questions' => $list, 'note' => 'Ask these, then call answer_questions again with the answers under the keys given (or call with answers {} to skip them).'];
        }
        // Complete: record it (the payment guard requires it for a chat-mode case) and surface any notice.
        self::call_ajax([CD_Ajax::instance(), 'handle_save_intake'], ['case_id' => $case_id, 't' => CD_Case::access_token($case_id), 'service_type' => $case->service_type, 'intake_complete' => '1']);
        $out = ['saved' => true, 'ready_to_pay' => true, 'price_aud' => (int) CD_Case::price($case->service_type), 'next' => 'Call get_payment_link and give the person the link.'];
        if (class_exists('CD_Use_Cases') && method_exists('CD_Use_Cases', 'eligibility_check')) {
            $el = CD_Use_Cases::eligibility_check($case->service_type, CD_Case::get_data($case_id));
            if ($el) { $out['notice'] = wp_strip_all_tags((string) ($el['headline'] ?? '') . ' ' . implode(' ', (array) ($el['issues'] ?? []))); $out['notice_blocks_payment'] = !empty($el['block']); $out['next'] = !empty($el['block']) ? 'This document is not sold for these answers.' : 'Read the notice to the person; call get_payment_link with acknowledge_notice=true once they have heard it.'; }
        }
        return $out;
    }

    public static function tool_get_payment_link($a) {
        list($case, $e) = self::auth($a); if ($e) return $e;
        $case_id = (int) $case->id;
        if (!empty($case->payment_id)) return ['paid' => true, 'message' => 'This case is already paid.', 'dashboard_url' => self::dashboard($case)];
        $cd = (array) CD_Case::get_data($case_id);
        if (trim((string) ($cd['intake_stopped'] ?? '')) !== '') return ['error' => 'Claim Done cannot prepare this document for these answers, so there is nothing to pay for: ' . $cd['intake_stopped']];
        if (($cd['intake_complete'] ?? '') !== '1') return ['error' => 'The questions are not finished yet. Call answer_questions until it returns ready_to_pay=true, then ask for the payment link.'];
        $post = ['case_id' => $case_id, 't' => CD_Case::access_token($case_id), 'service_type' => $case->service_type];
        if (!empty($a['registered_post'])) $post['post_addon'] = '1';
        if (class_exists('CD_Use_Cases') && method_exists('CD_Use_Cases', 'eligibility_check')) {
            $el = CD_Use_Cases::eligibility_check($case->service_type, CD_Case::get_data($case_id));
            if ($el && !empty($a['acknowledge_notice'])) { $post['eligibility_ack'] = '1'; $post['eligibility_head'] = CD_Use_Cases::notice_print($el); }
            if ($el && empty($a['acknowledge_notice'])) return ['error' => 'Read this notice to the person first, then call again with acknowledge_notice=true: ' . wp_strip_all_tags((string) ($el['headline'] ?? '') . ' ' . implode(' ', (array) ($el['issues'] ?? [])))];
        }
        $r = self::call_ajax([CD_Ajax::instance(), 'handle_create_payment_intent'], $post);
        if (empty($r['success']) || empty($r['data']['checkout_url'])) return ['error' => is_string($r['data'] ?? null) ? $r['data'] : 'Could not open checkout.'];
        $amount = (float) ((array) CD_Case::get_data($case_id))['quoted_price'] + (!empty($a['registered_post']) && class_exists('CD_Post_Addon') ? (int) CD_Post_Addon::PRICE : 0);
        return ['checkout_url' => (string) $r['data']['checkout_url'], 'amount_aud' => $amount, 'currency' => 'AUD', 'how' => 'Hosted Stripe Checkout: card, Apple Pay, Google Pay or Link. The person pays there; Claim Done never sees card details. After payment the document is drafted automatically, usually within about 5 minutes; call get_status to check.', 'dashboard_url' => self::dashboard($case)];
    }

    private static function dashboard($case) {
        return home_url('/dashboard/?case=' . rawurlencode($case->case_ref) . '&t=' . CD_Case::access_token((int) $case->id));
    }

    public static function tool_get_status($a) {
        list($case, $e) = self::auth($a); if ($e) return $e;
        global $wpdb;
        $docs = (array) $wpdb->get_results($wpdb->prepare("SELECT id, doc_type, status, sent_at FROM {$wpdb->prefix}cd_documents WHERE case_id = %d ORDER BY id", (int) $case->id));
        $ready = array_filter($docs, function ($d) { return in_array($d->status, ['ready', 'sent', 'completed'], true); });
        $paid  = !empty($case->payment_id) || (float) $case->amount_paid > 0;
        $next  = !$paid ? 'Not paid yet: call get_payment_link.' : ($case->status === 'generating' || !$ready ? 'Paid; the draft is being written (usually about 5 minutes). Call get_status again shortly.' : ($case->status === 'pending_review' ? 'The draft is ready. Call get_document to read it; the person approves it on the dashboard.' : 'Done: status ' . $case->status . '.'));
        return ['case_id' => (int) $case->id, 'reference' => (string) $case->case_ref, 'service' => (string) $case->service_type, 'status' => (string) $case->status, 'paid' => $paid, 'document_ready' => (bool) $ready, 'documents' => array_map(function ($d) { return ['id' => (int) $d->id, 'type' => (string) $d->doc_type, 'status' => (string) $d->status, 'sent_at' => $d->sent_at]; }, $docs), 'dashboard_url' => self::dashboard($case), 'next' => $next];
    }

    public static function tool_get_document($a) {
        list($case, $e) = self::auth($a); if ($e) return $e;
        if (empty($case->payment_id) && (float) $case->amount_paid <= 0) return ['error' => 'Not paid yet: call get_payment_link.'];
        global $wpdb;
        $docs = (array) $wpdb->get_results($wpdb->prepare("SELECT id, doc_type, status, ai_content FROM {$wpdb->prefix}cd_documents WHERE case_id = %d AND status IN ('ready','sent','completed') ORDER BY id", (int) $case->id));
        if (!$docs) return ['ready' => false, 'message' => 'The draft is still being written. Call get_status again in a minute.'];
        $out = [];
        foreach ($docs as $d) {
            $text = trim(html_entity_decode(wp_strip_all_tags(preg_replace('/<(br|\/p|\/h[1-6]|\/li|\/tr)[^>]*>/i', "\n", (string) $d->ai_content)), ENT_QUOTES, 'UTF-8'));
            $out[] = ['id' => (int) $d->id, 'type' => (string) $d->doc_type, 'status' => (string) $d->status, 'text' => mb_substr($text, 0, 60000)];
        }
        $flow = CD_Case::service_flow($case->service_type);
        return ['ready' => true, 'documents' => $out, 'dashboard_url' => self::dashboard($case), 'approval' => $flow === 'instant_download' ? 'The person confirms the document on the dashboard and receives the final PDF by email.' : 'The person reads and approves the letter on the dashboard (2 edits or redrafts included); only then is it emailed to the other party, with a copy to them. The connector cannot approve or send on their behalf.'];
    }

    // ------------------------------------------------------------------ plumbing

    /** Run one of the site's AJAX handlers in-process: fresh nonce, the case token, JSON captured, wp_die intercepted. */
    private static function call_ajax(callable $handler, array $post) {
        $saved = [$_POST, $_REQUEST, $_GET];
        $post['nonce'] = wp_create_nonce('cd_intake');
        if (!isset($post['t']) && isset($post['case_id'])) $post['t'] = CD_Case::access_token((int) $post['case_id']);
        $_POST = $post; $_REQUEST = $post; $_GET = [];
        $thrower = function () { return function () { throw new CD_Mcp_Exit(); }; };
        // wp_send_json() calls plain die() unless WordPress thinks this is an AJAX request; make it wp_die(), which we intercept.
        add_filter('wp_doing_ajax', '__return_true', 999);
        add_filter('wp_die_ajax_handler', $thrower, 999);
        add_filter('wp_die_json_handler', $thrower, 999);
        add_filter('wp_die_handler', $thrower, 999);
        $level = ob_get_level();
        ob_start();
        try { call_user_func($handler); } catch (CD_Mcp_Exit $e) { /* wp_send_json finished */ }
        finally {
            $out = '';
            while (ob_get_level() > $level) $out = ob_get_clean() . $out;
            remove_filter('wp_doing_ajax', '__return_true', 999);
            remove_filter('wp_die_ajax_handler', $thrower, 999);
            remove_filter('wp_die_json_handler', $thrower, 999);
            remove_filter('wp_die_handler', $thrower, 999);
            list($_POST, $_REQUEST, $_GET) = $saved;
            status_header(200);
        }
        // Some handlers wrap their body in catch (\Throwable) and answer a second time when our exit exception
        // unwinds through them, so the buffer can hold two JSON objects: the first is the real answer.
        $j = json_decode(self::first_json($out), true);
        return is_array($j) ? $j : ['success' => false, 'data' => 'No response from the handler.'];
    }

    private static function first_json($s) {
        $start = strpos($s, '{');
        if ($start === false) return '';
        $depth = 0; $in = false; $esc = false;
        for ($i = $start, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i];
            if ($in) { if ($esc) { $esc = false; } elseif ($c === '\\') { $esc = true; } elseif ($c === '"') { $in = false; } continue; }
            if ($c === '"') { $in = true; continue; }
            if ($c === '{') $depth++;
            if ($c === '}') { $depth--; if ($depth === 0) return substr($s, $start, $i - $start + 1); }
        }
        return substr($s, $start);
    }

    private static function limited($key, $max, $window) {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $k  = 'cd_' . $key . '_' . md5($ip);
        $n  = (int) get_transient($k);
        set_transient($k, $n + 1, $window);
        return $n >= $max;
    }

    private static function count($tool) {
        $k = 'cd_mcp_calls_' . gmdate('Ymd');
        $c = (array) get_option($k, []);
        $c[$tool] = (int) ($c[$tool] ?? 0) + 1;
        update_option($k, $c, false);
    }

    /** /.well-known/mcp.json: a plain description for directories and agents. */
    public static function serve_meta() {
        nocache_headers();
        self::cors();
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode([
            'name'        => 'Claim Done',
            'description' => 'Australian legal documents drafted from a person\'s answers for a flat fee: letters of demand, tribunal applications (NCAT, VCAT, QCAT, SACAT, ACAT, NTCAT and the Magistrates Courts), cease and desist letters, notices and agreements. Not a law firm; no legal advice. The person pays on Stripe and approves every document before it is sent.',
            'url'         => home_url('/mcp'),
            'transport'   => 'streamable-http',
            'protocolVersion' => self::PROTOCOL,
            'authentication'  => 'none',
            'homepage'    => home_url('/connect-your-ai-assistant/'),
            'tools'       => array_map(function ($t) { return ['name' => $t['name'], 'description' => $t['description']]; }, self::tools()),
            'contact'     => 'support@claimdone.com.au',
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
}
