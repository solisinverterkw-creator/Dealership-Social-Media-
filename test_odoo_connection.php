<?php
// Fill these in, then run: php test_odoo_connection.php
// This ONLY reads data (version check, login check, a read-only count) —
// it never writes/modifies anything in Odoo.
$ODOO_URL = 'https://your-odoo-domain.com'; // no trailing slash
$ODOO_DB = 'your_database_name';
$ODOO_USERNAME = 'your_login_email_or_username';
$ODOO_PASSWORD = 'your_password';

function xmlRpcCall(string $endpoint, string $method, array $params): array
{
    $xml = xmlRpcEncode($method, $params);
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/xml']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'message' => "cURL error: $curlError"];
    }
    if ($httpCode !== 200) {
        return ['success' => false, 'message' => "HTTP $httpCode", 'raw' => mb_substr((string)$response, 0, 500)];
    }
    return xmlRpcDecode($response);
}

function xmlRpcEncode(string $method, array $params): string
{
    $paramsXml = '';
    foreach ($params as $p) {
        $paramsXml .= '<param><value>' . phpToXmlRpcValue($p) . '</value></param>';
    }
    return "<?xml version=\"1.0\"?><methodCall><methodName>{$method}</methodName><params>{$paramsXml}</params></methodCall>";
}

function phpToXmlRpcValue($v): string
{
    if (is_int($v)) return "<int>{$v}</int>";
    if (is_bool($v)) return '<boolean>' . ($v ? 1 : 0) . '</boolean>';
    if (is_array($v)) {
        if (array_is_list($v)) {
            $items = implode('', array_map(fn($x) => '<value>' . phpToXmlRpcValue($x) . '</value>', $v));
            return "<array><data>{$items}</data></array>";
        }
        $members = '';
        foreach ($v as $k => $val) {
            $members .= '<member><name>' . htmlspecialchars((string)$k) . '</name><value>' . phpToXmlRpcValue($val) . '</value></member>';
        }
        return "<struct>{$members}</struct>";
    }
    return '<string>' . htmlspecialchars((string)$v) . '</string>';
}

function xmlRpcDecode(string $xml): array
{
    libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml);
    if ($doc === false) {
        return ['success' => false, 'message' => 'Could not parse XML-RPC response', 'raw' => mb_substr($xml, 0, 500)];
    }
    if (isset($doc->fault)) {
        $faultString = (string)$doc->fault->value->struct->member[1]->value->string;
        return ['success' => false, 'message' => "Odoo fault: {$faultString}"];
    }
    $valueNode = $doc->params->param->value;
    return ['success' => true, 'value' => xmlRpcValueToPhp($valueNode)];
}

function xmlRpcValueToPhp($valueNode)
{
    if (isset($valueNode->int)) return (int)$valueNode->int;
    if (isset($valueNode->boolean)) return (bool)(int)$valueNode->boolean;
    if (isset($valueNode->string)) return (string)$valueNode->string;
    if (isset($valueNode->array)) {
        $out = [];
        foreach ($valueNode->array->data->value as $v) {
            $out[] = xmlRpcValueToPhp($v);
        }
        return $out;
    }
    if (isset($valueNode->struct)) {
        $out = [];
        foreach ($valueNode->struct->member as $m) {
            $out[(string)$m->name] = xmlRpcValueToPhp($m->value);
        }
        return $out;
    }
    return (string)$valueNode;
}

echo "=== Step 1: Is the XML-RPC endpoint reachable at all? ===\n";
$versionResult = xmlRpcCall("{$ODOO_URL}/xmlrpc/2/common", 'version', []);
if (!$versionResult['success']) {
    echo "FAILED: " . $versionResult['message'] . "\n";
    echo "-> XML-RPC endpoint is not reachable (blocked, wrong URL, or disabled).\n";
    exit(1);
}
echo "OK — Odoo version info: " . json_encode($versionResult['value']) . "\n\n";

echo "=== Step 2: Can these credentials log in? ===\n";
$authResult = xmlRpcCall("{$ODOO_URL}/xmlrpc/2/common", 'authenticate', [$ODOO_DB, $ODOO_USERNAME, $ODOO_PASSWORD, []]);
if (!$authResult['success'] || !$authResult['value']) {
    echo "FAILED: Login rejected (wrong db/username/password, or API login disabled for this user).\n";
    if (!$authResult['success']) echo "Detail: " . $authResult['message'] . "\n";
    exit(1);
}
$uid = $authResult['value'];
echo "OK — logged in, uid = {$uid}\n\n";

echo "=== Step 3: Can we READ crm.lead (CRM Leads/Enquiries)? ===\n";
$countResult = xmlRpcCall("{$ODOO_URL}/xmlrpc/2/object", 'execute_kw', [
    $ODOO_DB, $uid, $ODOO_PASSWORD, 'crm.lead', 'search_count', [[]],
]);
if (!$countResult['success']) {
    echo "FAILED: " . $countResult['message'] . "\n";
    echo "-> Either no read access to crm.lead, or the model name differs on this install.\n";
    exit(1);
}
echo "OK — this account can see {$countResult['value']} CRM lead/enquiry record(s) total.\n\n";
echo "All checks passed — the scraper approach will work with these credentials.\n";
