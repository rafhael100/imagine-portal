<?php

declare(strict_types=1);

/**
 * P0 / NO-GO: actual synthetic Astro -> Portal purchase -> Sites PG18 outbox
 * then ACTUAL Sites F1-131 / CRM F3-11 classes for metadata inspection.
 *
 * This proves CRM denial even after the Site outbox is "delivered" in a
 * disposable PostgreSQL worker simulation. It is NOT a CRM lead delivery,
 * not an authenticated binding, and contains NO visitor payload or consent.
 */
if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('SITES_P0_AFTER_PURCHASE_LAB') !== '1'
    || getenv('CRM_P0_AFTER_SITES_LAB') !== '1'
    || getenv('SITES_F360_PG_LAB') !== '1'
    || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_PORT') !== '5432'
    || getenv('DB_DATABASE') !== 'sites_f360_lab'
    || getenv('DB_PASSWORD') !== 'f360-fictional-admin-disposable-only') {
    fwrite(STDERR, "P0 CRM metadata inspection requires isolated GitHub CI.\n");
    exit(2);
}

$root = dirname(__DIR__, 3);
$sitesSrc = $root.'/_sites_lead_candidate/src/Integration';
$crmSrc = $root.'/_crm_metadata_candidate/app/Core/Leads';
foreach ([
    $sitesSrc.'/SyntheticCrmLeadEnvelopeV1Draft.php',
    $sitesSrc.'/SyntheticLeadBindingAuthoritySnapshot.php',
    $sitesSrc.'/SyntheticLeadBindingAuthoritySource.php',
    $sitesSrc.'/SyntheticLeadBindingAuthorityAssessment.php',
    $sitesSrc.'/SyntheticLeadBindingAuthorityPreflight.php',
    $crmSrc.'/SyntheticLeadEnvelopeV1Assessment.php',
    $crmSrc.'/SyntheticLeadEnvelopeV1Inspector.php',
] as $file) {
    if (!is_file($file) || is_link($file)) {
        throw new RuntimeException('Pinned CRM/Sites source fixture not present.');
    }
    require_once $file;
}

use App\Core\Leads\SyntheticLeadEnvelopeV1Inspector as CrmEnvelopeInspector;
use Imagine\Sites\Integration\SyntheticCrmLeadEnvelopeV1Draft as LeadDraft;
use Imagine\Sites\Integration\SyntheticLeadBindingAuthoritySnapshot as BindingSnapshot;
use Imagine\Sites\Integration\SyntheticLeadBindingAuthoritySource as BindingSource;
use Imagine\Sites\Integration\SyntheticLeadBindingAuthorityPreflight as BindingPreflight;
use Imagine\Sites\Integration\SyntheticLeadBindingAuthorityAssessment as BindingDecision;

function p0crmAssert(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException('NO-GO CRM check: '.$label);
}

function p0crmUuid(string $scope): string
{
    // Generates only local fictional correlation IDs, NOT client/tenant IDs.
    $hex = substr(hash('sha256', 'imagine-ci-only-p0-no-go|'.$scope), 0, 32);
    $hex[12] = '4';
    $hex[16] = '8';
    return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4)
        .'-'.substr($hex,16,4).'-'.substr($hex,20,12);
}

function p0crmNoAuthority(BindingDecision $r, string $expected): void
{
    p0crmAssert($r->reasonCode === $expected, 'reason code mismatch: '.$expected);
    p0crmAssert(!$r->canAcceptLead() && !$r->canDeliverToCrm()
        && !$r->canAcknowledgeVisitor() && !$r->canRetryAutomatically(),
        'no authority preflight may authorize CRM delivery');
}

$dsn = 'pgsql:host=127.0.0.1;port=5432;connect_timeout=5;dbname=sites_f360_lab';
$db = new PDO($dsn,'postgres','f360-fictional-admin-disposable-only',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false,
    PDO::ATTR_TIMEOUT=>6,
]);
p0crmAssert(
    $db->query('SELECT current_database()')->fetchColumn()==='sites_f360_lab',
    'must read the real discarded Sites PG18 database'
);
p0crmAssert(
    (int)$db->query('SHOW server_version_num')->fetchColumn() >= 180000,
    'PostgreSQL 18 required'
);

$sql=$db->query(
    "SELECT a.tenant_ref,a.operation_ref,a.site_ref,a.product_code,
            o.delivery_state,o.delivery_attempts
     FROM sites_f360.admissions a
     JOIN sites_f360.outbox o USING (tenant_ref,operation_ref)
     WHERE a.operation_ref LIKE 'idem-p0-%'
     ORDER BY a.operation_ref"
);
$events=$sql->fetchAll(PDO::FETCH_ASSOC);
p0crmAssert(count($events)===2,
    'the actual two Portal-originated Site admissions must exist');
$crm=new CrmEnvelopeInspector();
$assessed=0;
$admitted=0;
$siteRefs=[];

foreach ($events as $event) {
    p0crmAssert($event['tenant_ref']==='tenant-f360-b'
        && in_array($event['product_code'],['onepage','landing_page'],true)
        && $event['delivery_state']==='delivered'
        && (int)$event['delivery_attempts']===1
        && str_starts_with($event['operation_ref'],'idem-p0-'),
        'only exactly delivered, synthetic Portal Site outbox events are inspected');
    p0crmAssert(!isset($siteRefs[$event['site_ref']])
        && str_starts_with($event['site_ref'],'site-p0-'),
        'each inspected synthetic Site must be distinct');
    $siteRefs[$event['site_ref']]=true;

    $draft=new LeadDraft(
        p0crmUuid('fictional-org'),
        p0crmUuid('binding|'.$event['site_ref']),
        p0crmUuid('submission|'.$event['operation_ref']),
        p0crmUuid('site|'.$event['site_ref']),
        p0crmUuid('form|'.$event['site_ref']),
        p0crmUuid('destination|'.$event['site_ref'])
    );
    $metadata=$draft->metadata();
    p0crmAssert(array_keys($metadata)===[
        'protocol','schema_version','organization_reference','binding_reference',
        'submission_reference','site_reference','form_reference','destination_reference'
    ],'Sites envelope contains only the expected fields');
    p0crmAssert($metadata['protocol']==='imagine.lead-intake'
        && $metadata['schema_version']===1
        && !$draft->canAuthorizeDelivery(),'metadata alone never provides permission');

    $assessment=$crm->inspect($metadata);
    p0crmAssert($assessment->reasonCode==='STRUCTURE_ONLY_UNVERIFIED'
        && $assessment->isStructurallyCompatible()
        && !$assessment->canAcceptSubmission()
        && !$assessment->canDeliverToCrm()
        && !$assessment->canAcknowledgeDurableReceipt(),
        'CRM must refuse accepting even syntactically compatible data');

    p0crmNoAuthority((new BindingPreflight())->inspect($draft),
        'AUTHORITY_UNAVAILABLE');
    $snapshot=new BindingSnapshot(
        $draft->organizationReference,$draft->bindingReference,
        $draft->siteReference,$draft->formReference,
        $draft->destinationReference,1,true
    );
    $source=new class($snapshot) implements BindingSource {
        public function __construct(private readonly BindingSnapshot $sample) {}
        public function currentBinding(
            string $organizationReference,string $bindingReference
        ): ?BindingSnapshot {
            // Explicitly a FAKE match for diagnostic coverage. Real service
            // authority, Portal identity and revocation are not present.
            return $this->sample;
        }
    };
    p0crmNoAuthority((new BindingPreflight($source))->inspect($draft),
        'AUTHORITY_MATCH_UNVERIFIED');

    $revoked=new BindingSnapshot(
        $draft->organizationReference,$draft->bindingReference,
        $draft->siteReference,$draft->formReference,
        $draft->destinationReference,2,false
    );
    $revokedSource=new class($revoked) implements BindingSource {
        public function __construct(private readonly BindingSnapshot $sample) {}
        public function currentBinding(
            string $organizationReference,string $bindingReference
        ): ?BindingSnapshot { return $this->sample; }
    };
    p0crmNoAuthority((new BindingPreflight($revokedSource))->inspect($draft),
        'BINDING_INACTIVE');

    $wrongTenant=new LeadDraft(
        p0crmUuid('foreign-org'),$draft->bindingReference,$draft->submissionReference,
        $draft->siteReference,$draft->formReference,$draft->destinationReference
    );
    p0crmAssert($crm->inspect($wrongTenant->metadata())->isStructurallyCompatible(),
        'UUID syntax is not proof of company identity');
    p0crmNoAuthority((new BindingPreflight($source))->inspect($wrongTenant),
        'BINDING_CONTEXT_MISMATCH');

    // Names, phone numbers, consent, or email cannot enter the metadata
    // proposal. Future actual lead payload needs explicit LGPD authorization.
    foreach ([
        ['email'=>'visitor@example.invalid'],
        ['name'=>'Test Person'],
        ['phone'=>'fake phone'],
        ['consent'=>true],
        ['authorizes_delivery'=>true],
        ['schema_version'=>2],
        ['destination_reference'=>p0crmUuid('different-destination')],
    ] as $mutation) {
        $changed=array_replace($metadata,$mutation);
        p0crmAssert(!$crm->inspect($changed)->canAcceptSubmission()
            && !$crm->inspect($changed)->canDeliverToCrm(),
            'malformed or forged metadata cannot authorize submission');
        p0crmAssert(!$crm->inspect($changed)->isStructurallyCompatible()
            || $changed['destination_reference']!==$metadata['destination_reference'],
            'extra fields/version/forged destination must change assessment');
    }
    $assessed++;
}
p0crmAssert($assessed===2 && count($siteRefs)===2
    && $admitted===0,'exactly two metadata candidates and zero CRM submissions');

// The existing Portal-Sites evidence is generated before the worker ACK.
// Add a SEPARATE small NO-GO record, never overwrite or upgrade the original.
$tmp=getenv('RUNNER_TEMP');
if (is_string($tmp) && $tmp!=='' && is_dir($tmp) && !is_link($tmp)) {
    $flags=[
        'schema'=>'imagine.p0.sites-crm-metadata-diagnostic.v1',
        'portal_originated_site_outbox_events_delivered'=>2,
        'sites_crm_metadata_drafts_checked'=>2,
        'crm_submissions_persisted'=>0,
        'crm_leads_created'=>0,
        'crm_delivery_authorized'=>false,
        'visitor_payload_inspected'=>false,
        'external_authority_verified'=>false,
        'real_customer_session_verified'=>false,
        'synthetic_only'=>true,
        'production_ready'=>false,
        'release_gate'=>'NO_GO',
        'crm_source_sha'=>'58ac8ee32483c9f6b70af95eac7207a7a9163200',
        'sites_lead_source_sha'=>'057b6f859df5050c842a86d18676e6332a79d1f9',
    ];
    $serialized=json_encode($flags,
        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $report=json_encode([
        'claims'=>$flags,'sha256'=>hash('sha256',$serialized),
        'signed'=>false
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $file=$tmp.'/p0-sites-crm-deny-evidence.json';
    if(file_exists($file)||is_link($file)
       ||file_put_contents($file,$report."\n",LOCK_EX)===false) {
        throw new RuntimeException('Synthetic evidence cannot be safely created.');
    }
}

echo "PASS: 2 delivered synthetic Site events -> 2 real Sites/CRM metadata inspections.\n";
echo "NO-GO: CRM lead count 0; no buyer, visitor, consent, authenticated binding or network delivery.\n";
