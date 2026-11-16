<?php
declare(strict_types=1);

require_once __DIR__ . '/../samples/DocumentFilename.php';
require_once __DIR__ . '/../samples/FormOptions.php';
require_once __DIR__ . '/../samples/RecordStatus.php';

use function Portfolio\document_filename;
use function Portfolio\data_field_name;
use function Portfolio\render_data_options;
use function Portfolio\set_record_active;

$passed = 0;
function check(bool $condition, string $description): void
{
    global $passed;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $description);
    }
    ++$passed;
    echo "PASS: {$description}\n";
}
function throws(callable $operation, string $expectedClass): bool
{
    try { $operation(); } catch (Throwable $error) { return $error instanceof $expectedClass; }
    return false;
}

check(document_filename('Demo Aurora ApS', 'Demo Aurora ApS - Agreement.pdf', '2026-11-16', 7)
    === 'demo-aurora-aps-agreement-2026-11-16-dokument-7.pdf', 'Duplicate company name is removed');
check(document_filename('Demo Aurora ApS', 'Agreement for Demo Aurora', '2026-11-16', 7)
    === 'demo-aurora-aps-agreement-2026-11-16-dokument-7.pdf', 'Trailing brand without legal suffix is removed');
check(str_contains(document_filename('Demo Aurora', 'Compare Demo Aurora with Demo Birch', '2026-11-16'),
    'compare-demo-aurora-with-demo-birch'), 'Company mentions inside a title are preserved');
check(str_starts_with(document_filename('Demo ÆØÅ', 'Aftale', '2026-11-16'), 'demo-aeoeaa-'),
    'Danish characters are transliterated');
$name = document_filename('../Demo <b>Aurora</b>', "Policy/..\\draft\r\n", '2026-11-16');
check(preg_match('/^[a-z0-9-]+\.pdf$/D', $name) === 1, 'Output excludes path separators, markup and line breaks');
check(strlen(document_filename(str_repeat('a', 200), str_repeat('b', 200), '2026-11-16', 7)) <= 190,
    'Long company and title segments are bounded');
check(str_contains(document_filename('', '', '2026-11-16'), 'virksomhed-webdokument-'), 'Empty labels have stable fallbacks');
check(throws(fn() => document_filename('Demo', 'Agreement', '2026-02-30'), InvalidArgumentException::class),
    'Impossible calendar date is rejected');
check(throws(fn() => document_filename('Demo', 'Agreement', '2026-11-16', -1), InvalidArgumentException::class),
    'Negative document ID is rejected');

check(data_field_name('supplier', 'data') === 'supplier_data', 'Category fields have distinct prefixes');
check(data_field_name('', 'data') === 'data', 'Unprefixed field name is retained');
$selected = ['Name'];
$html = render_data_options(['Name', '- Address', '<script>example</script>', '" onclick="example'],
    'data" onclick="example', static fn(string $field): array => $selected,
    static fn(array $values, string $item): string => in_array($item, $values, true) ? 'checked' : '', true);
check(!str_contains($html, '<script>'), 'Labels are HTML-escaped');
check(!str_contains($html, ' onclick="'), 'Attribute quotes cannot introduce an event handler');
check(str_contains($html, '&lt;script&gt;example&lt;/script&gt;'), 'Escaped label stays readable');
check(substr_count($html, ' checked>') === 1, 'Only the selected option is checked');
check(str_contains($html, 'checkbox-item parent') && str_contains($html, 'checkbox-item child'),
    'Hierarchical options retain parent and child classes');

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('Install pdo_sqlite to run the transaction tests.');
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('CREATE TABLE records (id INTEGER PRIMARY KEY, is_active INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE documents (id INTEGER PRIMARY KEY, record_id INTEGER REFERENCES records(id), is_active INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE audit_events (id INTEGER PRIMARY KEY, record_id INTEGER REFERENCES records(id), actor_id INTEGER NOT NULL, event_type TEXT NOT NULL)');
$pdo->exec('INSERT INTO records VALUES (1,1),(2,1)');
$pdo->exec('INSERT INTO documents VALUES (1,1,1),(2,1,1),(3,2,1)');
set_record_active($pdo, 1, false, 1);
check((int)$pdo->query('SELECT is_active FROM records WHERE id=1')->fetchColumn() === 0, 'Record is deactivated');
check((int)$pdo->query('SELECT SUM(is_active) FROM documents WHERE record_id=1')->fetchColumn() === 0,
    'Related documents follow the record status');
check((int)$pdo->query('SELECT is_active FROM documents WHERE record_id=2')->fetchColumn() === 1,
    'Unrelated documents are unchanged');
check($pdo->query('SELECT event_type FROM audit_events')->fetchColumn() === 'deactivated', 'Audit event is committed');
set_record_active($pdo, 1, true, 1);
check((int)$pdo->query('SELECT SUM(is_active) FROM documents WHERE record_id=1')->fetchColumn() === 2,
    'Reactivation restores related documents');

// Force a failure after the parent update and audit insert, at the document update.
$pdo->exec("CREATE TRIGGER fail_document_update BEFORE UPDATE ON documents BEGIN SELECT RAISE(ABORT, 'demo failure'); END");
$beforeEvents = (int)$pdo->query('SELECT COUNT(*) FROM audit_events')->fetchColumn();
check(throws(fn() => set_record_active($pdo, 1, false, 1), PDOException::class), 'Database failure is surfaced');
check((int)$pdo->query('SELECT is_active FROM records WHERE id=1')->fetchColumn() === 1,
    'Parent change is rolled back after a downstream failure');
check((int)$pdo->query('SELECT COUNT(*) FROM audit_events')->fetchColumn() === $beforeEvents,
    'Audit insert is rolled back with the failed change');
check(!$pdo->inTransaction(), 'Failure leaves no open transaction');
check(throws(fn() => set_record_active($pdo, 99, true, 1), RuntimeException::class), 'Missing record is rejected');
check(throws(fn() => set_record_active($pdo, 0, true, 1), InvalidArgumentException::class), 'Invalid IDs are rejected');
check(!$pdo->inTransaction(), 'Validation and missing-record errors leave no open transaction');
echo "\n{$passed} checks passed. Only fictional inputs and an in-memory database were used.\n";
