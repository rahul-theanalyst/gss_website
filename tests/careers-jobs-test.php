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
// Candidate-portal fallbacks (the list feed no longer has pay_rates or
// campus_portal_job_details_url)
check(portalPayLine('$ 95000 - 100000 / Yearly / W-2') === '$95,000–$100,000 / yearly', 'Portal pay range');
check(portalPayLine('$ 50 - 55 / Hourly / C2C') === '$50–$55 / hourly', 'Portal hourly pay');
check(portalPayLine('N/A') === '' && portalPayLine('') === '', 'No pay published');
check(payLineFor(['_portal' => ['payRateInfo' => '$ 80000 - 90000 / Yearly']]) === '$80,000–$90,000 / yearly', 'Pay falls back to portal');
check(portalCompanyId([['apply_job_login' => 'https://candidateportal.ceipal.com/login/COMPANY/JOB']]) === 'COMPANY', 'Company id from apply link');
check(transformJob(['public_job_title' => 'Dev', '_portal' => ['minExperience' => '6 Years']])['experience'] === '6 Years', 'Experience from portal');

$blocks = demoteLeadingFactHeadings(dropRepeatedTitle(htmlToBlocks(
    '<p><b>SDET - Playwright</b></p><p><b>Job Title: SDET - Playwright</b></p>'
    . '<p><b>Charlotte NC</b></p><p><b>Long Term</b></p><p><b>Role Overview</b></p><p>We build things.</p>'
    . '<div><div><strong>MUST HAVE</strong></div><div><ul><li>Java,</li><li>SQL</li></ul></div></div>'
), 'SDET - Playwright'));
check($blocks[0] === ['type' => 'para', 'text' => 'Charlotte NC'], 'Title repeats dropped, leading facts are plain lines');
check($blocks[2] === ['type' => 'heading', 'text' => 'Role Overview'], 'Real heading kept');
check($blocks[5]['type'] === 'list' && $blocks[5]['items'][0]['text'] === 'Java', 'List inside a wrapper div stays a list, trailing comma trimmed');

echo "Careers regression checks passed.\n";
