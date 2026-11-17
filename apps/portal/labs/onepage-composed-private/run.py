#!/usr/bin/env python3
"""Private composition on pinned #926 source. --check never opens PostgreSQL."""
import hashlib
import os
import pathlib
import subprocess
import sys

sys.dont_write_bytecode = True
HERE = pathlib.Path(__file__).resolve().parent
PORTAL = HERE.parent.parent
RUNNER = PORTAL / 'labs/onepage-binding-durable/run.py'
DECISION_MARKER = 'PASS COMPOSED_DECISION_DURABLE: one synthetic projection, rollback and stable replay'
BOUND_MARKER = 'PASS COMPOSED_BOUND_FINANCIAL: committed own binding, synthetic calculation only'
MARKER = 'PASS_OWN_REVIEW_PG: own snapshot, second buyer vs absent, no writes or billing'
EXACT_REVIEW = " if(($review['status']??null)!=='review')throw new RuntimeException('REVIEW_REQUIRED');"
ACCEPT = " $order=$orders->accept($own,'atomic-read-order',$quote,$accept);"


PINS = {
    'labs/onepage-composed-private/decision.sql.inc': '3f97cd6a0e73542271fe4ad2b42486f7a613adebce3e0ebb25aa91702b73f830',
    'labs/onepage-composed-private/decision-checkpoint.php': '4597de6424da90b3a7439310f4989a0186148e8e254dbf7f1a81d8bf71d2337a',
    'labs/onepage-composed-private/bound-read.sql.inc': 'f18f9d97d55dfa0f29be1f752be6660012c68d1262e85dd4d6c546666f7970e4',
    'labs/onepage-composed-private/bound-checkpoint.php': '461107f5fc158fe91071ca452e652488c4f2ffee3386710a8ec8c795c5f7e77f',
    'app/Homologation/Commerce/CommercialSelectionIntent.php': '16fcdbcacd4a995d7ed47f733074c8c566425dda093b51d0681551462859d311',
    'labs/onepage-native-admission/OwnReview.php': 'a5058928d80599c3bb9a70892eb33295c96847edf685ef30878335b107b478a4',
    'labs/onepage-native-admission/pg-increment.php': 'b710ef3529e5e1924fb75246665688e5aa41ab0c8bb169e1e194ec225d931032',
    'labs/onepage-installment-policy/CalendarArrears.php': 'af77a34fc435fd3764cee43fdb7be0894e6bcb00855dbccda9ac74127c3db9d0',
    'labs/onepage-installment-policy/Evaluator.php': '6a802eafe25fafb60ceb2024a82874feaffc1c0389e7c383b6747ee5f4113ac1',
    'labs/onepage-payment-observation/Observation.php': '600fec83e91c8badd2b4bce15b4471b50394ac46253c517f714460bf3f94e3ea',
    'labs/onepage-decision-projection/Projection.php': '02406e738c134df5673a8ab72ac8cb93413345a576372ea0a45afe12930637df',
    'labs/onepage-binding-durable/run.py': '316b16b3695c91950d119cc063c27b0d5f39a4b2416913dc7616422be5c2c3ac',
    'labs/onepage-binding-durable/binding-checks.php': 'f524b853420062de197add3ba0903ecdd7e648d29e01c34d1c8f4bb993e009e2',
    'labs/onepage-binding-durable/binding.sql': '33c946a62d8d5a7eb52bda887b0f7072f52b7f0039dc5103aa2dd1e53007fb55',
    'labs/onepage-atomic-read/fixture-tail.php.inc': 'a23c8976dc73a1acfdc18c562e80d27e08db7baba165bceb129000bbf1ed2e20',
    'labs/onepage-composed-private/own-checkpoint.php': '87b25a6dcb0505fd915f6d722b83a67e6d90910c04b687b641d36aa6330c7ff7',
    'labs/onepage-binding-producer/ProducerContract.php': '6b6ee65d396d733c558748915e5c0e750bfd81f9202a1a2c61be58c0acc5ac78',
    'app/Homologation/Commerce/ApprovedOfferCatalog.php': 'ab3666724f2ef5767c8e01ea0b08baede6415410dd8a8e6ea7ab521ac789c69b',
    'app/Homologation/Commerce/BillingSchedule.php': '825c4614fa9bb551506ca261fb18e1a13cd19453f8e6dca0893f3389704eb595',
    'app/Homologation/Commerce/CompositeQuotePreview.php': '4ccae8880e2121647da096ce5c3c4f194aa98cbac27b503120dec0d51f6866c4',
    'app/Homologation/Commerce/approved-catalog.json': '75aab147df8d937f6f0630c92eec8b4783dc345e308292abe7706bcb31a905fa',
}


def own_only(text, old, new):
    if text.count(old) != 1:
        raise ValueError('COMPOSITION_BOUNDARY_CHANGED')
    return text.replace(old, new)


def literal(path):
    return "'" + str(path).replace("\\", "\\\\").replace("'", "\\'") + "'"


def extend_binding(tmp):
    binding = PORTAL / 'labs/onepage-binding-durable'
    sql = (binding / 'binding.sql').read_text()
    sql = own_only(sql, ' PRIMARY KEY(order_id,line),',
                   ' bound_at timestamptz NOT NULL DEFAULT clock_timestamp(),\n PRIMARY KEY(order_id,line),')
    # Append inside the same disposable DDL transaction; runtime never applies DDL.
    sql = own_only(sql, '\nCOMMIT;\n', '\n' + (HERE / 'bound-read.sql.inc').read_text() + '\n' +
                   (HERE / 'decision.sql.inc').read_text() + '\nCOMMIT;\n')
    output_sql = tmp / 'bound-binding.sql'
    output_sql.write_text(sql)
    source = (binding / 'binding-checks.php').read_text()
    req = "require_once __DIR__.'/../onepage-binding-producer/ProducerContract.php';"
    source = own_only(source, req, 'require_once '+literal(HERE / 'bound-checkpoint.php')+';\n require_once '+literal(HERE / 'decision-checkpoint.php')+';')
    source = own_only(source, "file_get_contents(__DIR__.'/binding.sql')", 'file_get_contents('+literal(output_sql)+')')
    # No binding ID is fetched through bootstrap, even in the inherited oracle.
    source = own_only(source, 'SELECT order_id,line,account,environment,payment_id',
                      'SELECT order_id,line,account,environment')
    source = own_only(source, "$exact->payment_id==='pay_LABalpha'",
                      "$committedRead['payment_id']==='pay_LABalpha'")
    source = own_only(source, '    $db->unprepared($sql);',
                      '    $db->unprepared($sql);\n    assertComposedReadAcl($db);')
    before = "            $check($count()===0);"
    source = own_only(source, before, before+"\n            $check(readComposedBinding($b,$proof,$order,'setup-1')===null);")
    # First exact replay after concurrent session has committed (not its later replay).
    anchor = "    $check($bind($b,$proof,'setup-1')==='replay');\n    $seed('probe-dissent'"
    source = own_only(source, anchor, """    $committedRead=readComposedBinding($b,$proof,$order,'setup-1');
    $check($committedRead!==null);
    $check($bind($b,$proof,'setup-1')==='replay');
    $seed('probe-dissent'""")
    post = "    $check($latches()===$beforeLatches);"
    source = own_only(source, post, post+"""
    $check(readComposedBinding($b,$proof,$order,'setup-1')===$committedRead
        && readComposedBinding($b,$proof,$order,'setup-2')===null);
    foreach ([$sameCompanyOther,$foreign] as $other) {
        $check(readComposedBinding($b,$session($other),$order,'setup-1')===null);
    }
    $expect('42883',static fn()=>$runtime->query(
        "SELECT onepage_buyer_lab.fixture_read_bound_charge(NULL::text,NULL::uuid,NULL::text,NULL::text)"));
    $projected=checkComposedFinancial($runtime,$owner,$identity,$pinned,$proof,$order,$first,$committedRead);
    $decisionAfterRevocation=checkComposedDecision($db,$runtime,$b,$owner,$identity,$pinned,
        $proof,$session($sameCompanyOther),$session($foreign),$order,$first,$committedRead,$projected);
""")
    closure = "use($check,$bind,$b,$proof,$count,$pinned,$owner,$identity): void {"
    source = own_only(source, closure, "use($check,$bind,$b,$proof,$count,$pinned,$owner,$identity,$order,$decisionAfterRevocation): void {")
    revoked = "        $check($bind($b,$proof,'setup-1')==='denied' && $count()===1);"
    source = own_only(source, revoked, revoked+"\n        $check(readComposedBinding($b,$proof,$order,'setup-1')===null);\n        $decisionAfterRevocation();")
    out = tmp / 'bound-binding-checks.php'
    out.write_text(source)

    hook = tmp / 'atomic-plus-binding.php'
    hooked = hook.read_text()
    hook.write_text(own_only(hooked, literal(binding / 'binding-checks.php'), literal(out)))
    for path in (out, hook):
        subprocess.run([os.environ['PHP_BIN'], '-n', '-l', str(path)],
                       env={'PATH': '/usr/bin:/bin'}, check=True, capture_output=True)


def attach(parent):
    prior = parent.compose

    def compose(tmp):
        fixture = prior(tmp)
        extend_binding(tmp)
        text = fixture.read_text()
        if text.count(ACCEPT) != 1 or text.index(EXACT_REVIEW) >= text.index(ACCEPT):
            raise ValueError('ACCEPTANCE_BOUNDARY_CHANGED')
        checkpoint = (
            '\n require ' + literal(HERE / 'own-checkpoint.php') + ';'
            '\n checkComposedOwnReview($db,$runtime,$owner,$identity,$barrier,$own,'
            "requestOrder($sessions['same']),$quote,$review['review']);")
        composed = own_only(text, EXACT_REVIEW, EXACT_REVIEW + checkpoint)
        if composed.count(ACCEPT) != 1 or composed.index(checkpoint) >= composed.index(ACCEPT):
            raise ValueError('QUOTE_CONTINUITY_REQUIRED')
        fixture.write_text(composed)
        # Pin and lint bundled contracts without executing their PHP or loading autoload.
        for rel in PINS:
            if rel.endswith('.php'):
                subprocess.run([os.environ['PHP_BIN'], '-n', '-l', str(PORTAL / rel)],
                               env={'PATH': '/usr/bin:/bin'}, check=True, capture_output=True)
        return fixture

    parent.compose = compose
    parent.REQUIRED = parent.REQUIRED + [MARKER, BOUND_MARKER, DECISION_MARKER]


def main():
    for rel, digest in PINS.items():
        if hashlib.sha256((PORTAL / rel).read_bytes()).hexdigest() != digest:
            raise ValueError('SOURCE_PIN_DRIFT')
    # Compile only repository-pinned bytes with one fixed insertion; the
    # upstream wrapper keeps its original __file__, runtime guards and cleanup.
    source = own_only(RUNNER.read_text(), '    parent.main()',
                      '    attach(parent)\n    parent.main()')
    globals_ = {'__name__': 'composed_binding', '__file__': str(RUNNER), 'attach': attach}
    exec(compile(source, str(RUNNER), 'exec'), globals_)
    globals_['main']()


if __name__ == '__main__':
    try:
        main()
    except Exception:
        print('COMPOSED_RUN_FAILED: pins, preflight, SQL or cleanup incomplete', file=sys.stderr)
        sys.exit(1)
