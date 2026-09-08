<?php
/**
 * A school's public front page.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$school_id = absint( ( new \EduCBTPro\Core\TenantContext() )->resolve_from_host() ?? 0 );
$branding  = ( new \EduCBTPro\Services\DocumentBrandingService() )->letterhead( $school_id );

$school_name = (string) ( $branding['school_name'] ?? get_bloginfo( 'name' ) );
$logo        = (string) ( $branding['logo'] ?? '' );

// Fetch available trial subjects (without revealing question counts)
global $wpdb;
$subjects_table = \EduCBTPro\Core\Schema::table( 'subjects_v2' );
$trial_subjects = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT id, name FROM {$subjects_table} WHERE school_id = %d AND status = 'active' ORDER BY name ASC LIMIT 8",
        $school_id
    ),
    ARRAY_A
);

get_header();
?>
<style>
:root{
    --forest:#173D26; --forest-dark:#0E2718; --moss:#3F6B4A; --sage:#7C9473;
    --lemon:#D3E64B; --lemon-deep:#A9C21E; --lemon-soft:#F3F7DC;
    --cream:#FBFBF5; --ink:#152018; --muted:#63715F; --line:#E4E8DA;
    --white:#FFFFFF; --radius-lg:20px; --radius-md:14px; --radius-sm:10px;
    --shadow-sm:0 1px 3px rgba(20,40,25,.06);
    --shadow-md:0 8px 24px rgba(15,40,25,.09);
    --shadow-lg:0 20px 48px rgba(15,40,25,.14);
}
.edu-landing *{box-sizing:border-box;margin:0;padding:0;}
.edu-landing{font-family:'Inter',-apple-system,sans-serif;background:var(--cream);color:var(--ink);-webkit-font-smoothing:antialiased;}
.edu-landing h1,.edu-landing h2,.edu-landing h3{font-family:'Space Grotesk',sans-serif;letter-spacing:-0.01em;margin:0;}
.edu-landing a{color:inherit;text-decoration:none;}
.edu-landing ::selection{background:var(--lemon);color:var(--forest-dark);}

.edu-nav{
    display:flex;align-items:center;justify-content:space-between;
    padding:18px 40px;border-bottom:1px solid var(--line);
    background:rgba(251,251,245,.9);backdrop-filter:blur(8px);
    position:sticky;top:0;z-index:20;
}
.edu-brand{display:flex;align-items:center;gap:12px;}
.edu-brand-logo{max-height:40px;max-width:40px;border-radius:50%;object-fit:cover;}
.edu-brand-mark{
    width:36px;height:36px;border-radius:50%;background:var(--forest);
    position:relative;flex:none;
}
.edu-brand-mark::after{
    content:"";position:absolute;width:14px;height:14px;border-radius:50%;
    background:var(--lemon);top:8px;left:8px;
}
.edu-brand-name{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:18px;color:var(--forest-dark);}
.edu-brand-tag{font-size:11px;color:var(--muted);letter-spacing:.03em;}

.edu-btn{
    display:inline-flex;align-items:center;justify-content:center;gap:8px;
    border-radius:999px;font-weight:600;font-size:14.5px;padding:12px 24px;
    border:1.5px solid transparent;transition:transform .15s,box-shadow .15s,background .15s,border-color .15s;
    white-space:nowrap;cursor:pointer;font-family:inherit;
}
.edu-btn:active{transform:translateY(1px);}
.edu-btn-solid{background:var(--forest);color:var(--white);box-shadow:var(--shadow-sm);}
.edu-btn-solid:hover{background:var(--forest-dark);box-shadow:var(--shadow-md);transform:translateY(-1px);}
.edu-btn-ghost{background:var(--white);color:var(--forest-dark);border-color:var(--line);}
.edu-btn-ghost:hover{border-color:var(--lemon-deep);background:var(--lemon-soft);}
.edu-btn-lemon{background:var(--lemon);color:var(--forest-dark);}
.edu-btn-lemon:hover{background:var(--lemon-deep);transform:translateY(-1px);}

/* Hero */
.edu-hero-wrap{position:relative;overflow:hidden;background:radial-gradient(circle at 8px 8px,rgba(23,61,38,.07) 1.6px,transparent 1.6px);background-size:26px 26px;}
.edu-hero{max-width:1180px;margin:0 auto;padding:80px 40px 72px;display:grid;grid-template-columns:1.05fr .95fr;gap:56px;align-items:center;}
.edu-hero h1{font-size:clamp(32px,4.5vw,50px);font-weight:700;line-height:1.06;color:var(--forest-dark);}
.edu-hero h1 em{font-style:normal;background:linear-gradient(180deg,transparent 62%,var(--lemon) 62%);}
.edu-hero-ctas{display:flex;gap:14px;margin-top:32px;flex-wrap:wrap;}
.edu-hero-stats{display:flex;gap:28px;margin-top:40px;padding-top:28px;border-top:1px solid var(--line);max-width:440px;}
.edu-stat-num{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:22px;color:var(--forest-dark);}
.edu-stat-label{font-size:12.5px;color:var(--muted);margin-top:2px;}

/* OMR answer sheet card */
.edu-sheet{
    background:var(--white);border:1px solid var(--line);border-radius:var(--radius-lg);
    box-shadow:var(--shadow-lg);padding:26px 26px 22px;position:relative;
    max-width:380px;margin-left:auto;transform:rotate(1.2deg);
}
.edu-sheet::before{
    content:"";position:absolute;inset:14px -14px -14px 14px;
    border:1.5px solid var(--line);border-radius:var(--radius-lg);z-index:-1;
    background:var(--lemon-soft);transform:rotate(-2.4deg);
}
.edu-sheet-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;}
.edu-sheet-dot{width:8px;height:8px;border-radius:50%;background:var(--lemon-deep);}
.edu-sheet-title{font-size:12.5px;font-weight:700;color:var(--forest-dark);letter-spacing:.03em;}
.edu-sheet-row{display:flex;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px dashed var(--line);}
.edu-sheet-row:last-of-type{border-bottom:none;}
.edu-sheet-q{font-size:12.5px;color:var(--muted);font-weight:600;width:60px;}
.edu-bubbles{display:flex;gap:8px;}
.edu-bubble{width:22px;height:22px;border-radius:50%;border:1.5px solid var(--line);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:var(--muted);background:var(--white);}
.edu-bubble.filled{background:var(--forest);border-color:var(--forest);color:var(--white);}
.edu-sheet-foot{margin-top:16px;padding-top:14px;border-top:1px solid var(--line);display:flex;justify-content:space-between;font-size:11.5px;color:var(--muted);}

@media(max-width:900px){.edu-hero{grid-template-columns:1fr;padding:56px 24px 48px;}.edu-sheet{margin:0 auto;transform:none;}.edu-sheet::before{display:none;}}

/* Subject strip */
.edu-strip{max-width:1180px;margin:0 auto;padding:0 40px 80px;}
.edu-strip-head{display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:26px;flex-wrap:wrap;gap:10px;}
.edu-strip-head h2{font-size:24px;color:var(--forest-dark);}
.edu-strip-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;}
@media(max-width:900px){.edu-strip-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:520px){.edu-strip-grid{grid-template-columns:1fr;}}
.edu-chip{
    background:var(--white);border:1px solid var(--line);border-radius:var(--radius-md);
    padding:18px;display:flex;flex-direction:column;gap:14px;
    transition:transform .15s,box-shadow .15s,border-color .15s;
}
.edu-chip:hover{transform:translateY(-3px);box-shadow:var(--shadow-md);border-color:transparent;}
.edu-chip-icon{
    width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;
    font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:12.5px;color:var(--white);
}
.edu-chip-name{font-weight:600;font-size:14.5px;color:var(--forest-dark);}

/* How it works */
.edu-steps-wrap{background:var(--forest-dark);}
.edu-steps{max-width:1180px;margin:0 auto;padding:72px 40px;color:var(--white);}
.edu-steps h2{font-size:26px;margin-bottom:40px;max-width:24ch;}
.edu-step-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:28px;}
@media(max-width:820px){.edu-step-grid{grid-template-columns:1fr;}}
.edu-step-num{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:14px;color:var(--lemon);margin-bottom:10px;}
.edu-step-title{font-size:16px;font-weight:600;margin-bottom:8px;color:var(--white);}
.edu-step-desc{font-size:14px;line-height:1.6;color:rgba(255,255,255,.6);}

/* Footer */
.edu-footer{
    border-top:1px solid var(--line);padding:24px 40px;
    display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;
    font-size:13px;color:var(--muted);
}
.edu-footer-links{display:flex;gap:20px;}
.edu-footer-links a{opacity:.75;transition:opacity .15s;}
.edu-footer-links a:hover{opacity:1;}

@media(max-width:820px){.edu-nav{padding:14px 20px;}.edu-strip{padding:0 20px 60px;}.edu-steps{padding:56px 20px;}.edu-footer{padding:20px;}}
</style>

<main class="edu-landing">
    <nav class="edu-nav">
        <div class="edu-brand">
            <?php if ( $logo !== '' ) : ?>
                <img class="edu-brand-logo" src="<?php echo esc_url( $logo ); ?>" alt="">
            <?php else : ?>
                <div class="edu-brand-mark"></div>
            <?php endif; ?>
            <div>
                <div class="edu-brand-name"><?php echo esc_html( $school_name ); ?></div>
                <div class="edu-brand-tag">CBT Portal</div>
            </div>
        </div>
        <div style="display:flex;gap:10px;">
            <a class="edu-btn edu-btn-ghost" href="<?php echo esc_url( wp_login_url( home_url( '/portal/' ) ) ); ?>">Sign in</a>
            <a class="edu-btn edu-btn-solid" href="<?php echo esc_url( home_url( '/trial/' ) ); ?>">Try a test</a>
        </div>
    </nav>

    <div class="edu-hero-wrap">
        <div class="edu-hero">
            <div>
                <h1>Sit the real thing <em>before</em> exam day.</h1>
                <div class="edu-hero-ctas">
                    <a class="edu-btn edu-btn-solid" href="<?php echo esc_url( wp_login_url( home_url( '/portal/' ) ) ); ?>">Go to Portal</a>
                    <a class="edu-btn edu-btn-ghost" href="<?php echo esc_url( home_url( '/trial/' ) ); ?>">Try a Practice Exam</a>
                </div>
                <div class="edu-hero-stats">
                    <div>
                        <div class="edu-stat-num"><?php echo count( $trial_subjects ); ?>+</div>
                        <div class="edu-stat-label">Subjects covered</div>
                    </div>
                    <div>
                        <div class="edu-stat-num">CBT</div>
                        <div class="edu-stat-label">Exam interface</div>
                    </div>
                    <div>
                        <div class="edu-stat-num">24/7</div>
                        <div class="edu-stat-label">Practice access</div>
                    </div>
                </div>
            </div>
            <div>
                <div class="edu-sheet">
                    <div class="edu-sheet-head">
                        <span class="edu-sheet-dot"></span>
                        <span class="edu-sheet-title">ANSWER SHEET</span>
                    </div>
                    <div class="edu-sheet-row"><span class="edu-sheet-q">Q1</span><div class="edu-bubbles"><div class="edu-bubble filled">A</div><div class="edu-bubble">B</div><div class="edu-bubble">C</div><div class="edu-bubble">D</div></div></div>
                    <div class="edu-sheet-row"><span class="edu-sheet-q">Q2</span><div class="edu-bubbles"><div class="edu-bubble">A</div><div class="edu-bubble filled">B</div><div class="edu-bubble">C</div><div class="edu-bubble">D</div></div></div>
                    <div class="edu-sheet-row"><span class="edu-sheet-q">Q3</span><div class="edu-bubbles"><div class="edu-bubble">A</div><div class="edu-bubble">B</div><div class="edu-bubble filled">C</div><div class="edu-bubble">D</div></div></div>
                    <div class="edu-sheet-row"><span class="edu-sheet-q">Q4</span><div class="edu-bubbles"><div class="edu-bubble">A</div><div class="edu-bubble">B</div><div class="edu-bubble">C</div><div class="edu-bubble filled">D</div></div></div>
                    <div class="edu-sheet-foot"><span>4 of 5 answered</span><span>01:14 left</span></div>
                </div>
            </div>
        </div>
    </div>

    <?php if ( ! empty( $trial_subjects ) ) : ?>
    <div class="edu-strip">
        <div class="edu-strip-head">
            <h2>Pick up where you're weakest</h2>
            <a class="edu-btn edu-btn-ghost" href="<?php echo esc_url( home_url( '/trial/' ) ); ?>">Start practising &rarr;</a>
        </div>
        <div class="edu-strip-grid">
            <?php
            $colors = ['var(--forest)', 'var(--lemon-deep)', 'var(--sage)', 'var(--moss)'];
            foreach ( $trial_subjects as $i => $subj ) :
                $initials = '';
                $words = explode( ' ', $subj['name'] );
                foreach ( $words as $w ) { $initials .= strtoupper( substr( $w, 0, 1 ) ); }
                $initials = substr( $initials, 0, 3 );
                $color = $colors[ $i % count( $colors ) ];
                $text_color = ( $color === 'var(--lemon-deep)' ) ? 'var(--forest-dark)' : 'var(--white)';
            ?>
                <a class="edu-chip" href="<?php echo esc_url( home_url( '/trial/' ) ); ?>">
                    <div class="edu-chip-icon" style="background:<?php echo esc_attr( $color ); ?>;color:<?php echo esc_attr( $text_color ); ?>"><?php echo esc_html( $initials ); ?></div>
                    <div class="edu-chip-name"><?php echo esc_html( $subj['name'] ); ?></div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="edu-steps-wrap">
        <div class="edu-steps">
            <h2>Three steps between now and a calmer exam morning</h2>
            <div class="edu-step-grid">
                <div>
                    <div class="edu-step-num">01</div>
                    <div class="edu-step-title">Pick a subject</div>
                    <div class="edu-step-desc">Jump straight into any subject — no account needed for a practice run.</div>
                </div>
                <div>
                    <div class="edu-step-num">02</div>
                    <div class="edu-step-title">Sit a timed round</div>
                    <div class="edu-step-desc">The same interface used on the full portal exam — so nothing feels unfamiliar later.</div>
                </div>
                <div>
                    <div class="edu-step-num">03</div>
                    <div class="edu-step-title">See where you stand</div>
                    <div class="edu-step-desc">Review what you got right, revisit the topics that need another look, and go again.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="edu-footer">
        <span>&copy; <?php echo esc_html( date( 'Y' ) ); ?> <?php echo esc_html( $school_name ); ?></span>
        <div class="edu-footer-links">
            <a href="<?php echo esc_url( wp_login_url( home_url( '/portal/' ) ) ); ?>">Portal</a>
            <a href="<?php echo esc_url( home_url( '/trial/' ) ); ?>">Practice</a>
        </div>
    </div>
</main>

<?php
get_footer();
