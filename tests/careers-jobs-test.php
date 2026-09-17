<?php
define('CEIPAL_JOBS_TEST_MODE', true);
require __DIR__ . '/../server/careers-jobs.php';

function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}

$job = transformJob([
    'job_code' => '123',
    'public_job_title' => 'Engineer',
    'multpile_job_location' => '(Austin, TX), (Boston, MA)',
    'remote_opportunities' => 'No',
    'tax_terms' => 'Contract',
    'public_job_desc' => '<p>Build &amp; test</p><p>Work together</p>',
    'apply_job_without_registration' => 'https://jobsapi.ceipal.com/apply/123',
]);
check($job['type'] === 'Contract', 'No must not become Remote');
check($job['location'] === 'Austin, TX; Boston, MA', 'Multiple locations');
check($job['description'] === "Build & test\nWork together", 'Plain description');
check($job['applyLink'] === 'https://jobsapi.ceipal.com/apply/123', 'Apply URL');
check(transformJob(['public_job_title' => '']) === null, 'Skip missing titles');
check(transformJob(['public_job_title' => 'Engineer', 'remote_opportunities' => 'Yes'])['type'] === 'Remote', 'Remote flag');

$calls = [];
$jobs = fetchAllJobs([], function ($page) use (&$calls) {
    $calls[] = $page;
    return ['count' => 7, 'num_pages' => 7, 'results' => [['id' => $page]]];
});
check(count($jobs) === 7 && count($calls) === 7, 'Fetch beyond the old five-page cap');
check(fetchAllJobs([], function ($page) {
    return $page === 1 ? ['count' => 2, 'results' => [['id' => 1]]] : null;
}) === null, 'Do not cache partial responses');
check(fetchAllJobs([], function () { return ['message' => 'Unexpected response']; }) === null, 'Reject malformed results');
check(fetchAllJobs([], function () { return ['count' => 0, 'results' => []]; }) === [], 'Valid empty feed');
check(fetchAllJobs([], function () { return ['count' => 2, 'num_pages' => 1, 'results' => [['id' => 1]]]; }) === null, 'Reject truncated feed');
echo "Careers regression checks passed.\n";
