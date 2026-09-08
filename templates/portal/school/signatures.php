<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

$school_id   = (int) ( $educbt['school_id'] ?? 0 );
$wp_user_id  = get_current_user_id();
$sig_service = new \EduCBTPro\Services\SignatureService();

// Determine the user's role for the signature form.
// Principals (MANAGE_SCHOOL) -> 'principal'
// Exam officers (MANAGE_PAPERS but not MANAGE_SCHOOL) -> 'exam_officer'
$is_principal    = \EduCBTPro\Core\Gate::allows( \EduCBTPro\Core\Capabilities::MANAGE_SCHOOL );
$is_exam_officer = \EduCBTPro\Core\Gate::allows( \EduCBTPro\Core\Capabilities::MANAGE_PAPERS )
    && ! $is_principal;

$sig_role  = $is_principal ? 'principal' : ( $is_exam_officer ? 'exam_officer' : 'class_teacher' );
$sig_label = $is_principal ? 'Principal' : ( $is_exam_officer ? 'Exam Officer' : 'Class Teacher' );

// Look up the staff record ID
$staff_row = $wpdb->get_row(
    $wpdb->prepare(
        'SELECT id, first_name, last_name FROM ' . \EduCBTPro\Core\Schema::table( 'staff' ) . ' WHERE wp_user_id = %d AND school_id = %d LIMIT 1',
        $wp_user_id,
        $school_id
    ),
    ARRAY_A
);

$staff_id   = (int) ( $staff_row['id'] ?? 0 );
$staff_name = $staff_row ? trim( $staff_row['first_name'] . ' ' . $staff_row['last_name'] ) : '';

// Fallback: if no staff record exists, use wp_user_id
if ( $staff_id === 0 ) {
    $staff_id = $wp_user_id;
}

// Fallback: if no staff name, try school settings for principal
if ( $staff_name === '' && $is_principal ) {
    $school_row = $wpdb->get_row(
        $wpdb->prepare(
            'SELECT principal_name FROM ' . \EduCBTPro\Core\Schema::table( 'schools' ) . ' WHERE id = %d LIMIT 1',
            $school_id
        ),
        ARRAY_A
    );
    $staff_name = (string) ( $school_row['principal_name'] ?? '' );
}

// Get the existing signature for this staff member + role
$existing = $sig_service->get_for_staff( $school_id, $staff_id, $sig_role );

$default_name = $existing['name'] ?? ( $staff_name !== '' ? $staff_name : '' );

$educbt_title = 'My Signature';
$educbt_body = static function() use ( $existing, $staff_id, $default_name, $sig_role, $sig_label ): void {
    $flash = \EduCBTPro\Frontend\PortalActions::flash();
    if ( ! empty( $flash['error'] ) ) {
        echo '<div class="educbt-flash educbt-flash--error">' . esc_html( $flash['error'] ) . '</div>';
    }
    if ( ! empty( $flash['result'] ) && ( $flash['result']['type'] ?? '' ) === 'saved' ) {
        echo '<div class="educbt-flash educbt-flash--success">Signature saved.</div>';
    }
    ?>
    <p class="educbt-help">Your <?php echo esc_html( $sig_label ); ?> signature appears on report sheets and result documents. Draw, type, or upload below.</p>

    <?php if ( $existing ) : ?>
        <div style="margin:16px 0;padding:16px;border:1px solid #e2e8f0;border-radius:8px;">
            <strong>Current Signature:</strong><br>
            <?php if ( ( $existing['type'] ?? '' ) === 'text' ) : ?>
                <span style="font-family:'Brush Script MT','Segoe Script',cursive;font-size:28px;"><?php echo esc_html( $existing['data'] ); ?></span>
            <?php else : ?>
                <img src="<?php echo esc_url( $existing['data'] ); ?>" alt="Signature" style="max-height:60px;margin-top:8px;"><br>
            <?php endif; ?>
            <br><span style="font-size:14px;color:#64748b;"><?php echo esc_html( $existing['name'] ); ?></span>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="educbt-form">
        <?php wp_nonce_field( 'educbt_save_signature' ); ?>
        <input type="hidden" name="action" value="educbt_save_signature">
        <input type="hidden" name="signature_role" value="<?php echo esc_attr( $sig_role ); ?>">
        <input type="hidden" name="staff_id" value="<?php echo esc_attr( (string) $staff_id ); ?>">

        <div class="educbt-field">
            <label>Display Name</label>
            <input type="text" name="display_name" placeholder="e.g. Mr. A. Johnson" required value="<?php echo esc_attr( $default_name ); ?>">
        </div>

        <div class="educbt-field">
            <label>Method</label>
            <select name="signature_type" id="sig-type">
                <option value="digital">Draw on canvas</option>
                <option value="text">Type a text signature</option>
                <option value="upload">Upload image</option>
            </select>
        </div>

        <div id="sig-canvas-wrap" class="educbt-field">
            <label>Draw Signature</label>
            <canvas id="sig-canvas" width="400" height="150" style="border:1px solid #cbd5e1;border-radius:8px;cursor:crosshair;background:#fff;"></canvas>
            <br><button type="button" id="sig-clear" class="educbt-btn educbt-btn--sm">Clear</button>
            <input type="hidden" name="signature_data" id="sig-data">
        </div>

        <div id="sig-text-wrap" class="educbt-field" style="display:none">
            <label>Type Your Signature</label>
            <input type="text" name="signature_text" id="sig-text-input" placeholder="Sign here..." style="font-family:'Brush Script MT','Segoe Script',cursive;font-size:24px;height:48px;">
            <p style="font-size:13px;color:#64748b;margin-top:4px;">This will be rendered in a script font on the report sheet.</p>
        </div>

        <div id="sig-upload-wrap" class="educbt-field" style="display:none">
            <label>Upload Image</label>
            <input type="file" name="signature_file" accept="image/png,image/jpeg,image/gif,image/svg+xml">
        </div>

        <button type="submit" class="educbt-btn educbt-btn--primary">Save Signature</button>
    </form>

    <script>
    (function() {
        var canvas = document.getElementById('sig-canvas');
        var ctx = canvas.getContext('2d');
        var drawing = false;
        ctx.strokeStyle = '#1e293b'; ctx.lineWidth = 2; ctx.lineCap = 'round';
        function p(e){var r=canvas.getBoundingClientRect();return[((e.touches?e.touches[0].clientX:e.clientX)-r.left)*(canvas.width/r.width),((e.touches?e.touches[0].clientY:e.clientY)-r.top)*(canvas.height/r.height)];}
        canvas.addEventListener('mousedown',function(e){drawing=true;ctx.beginPath();var c=p(e);ctx.moveTo(c[0],c[1]);});
        canvas.addEventListener('mousemove',function(e){if(!drawing)return;var c=p(e);ctx.lineTo(c[0],c[1]);ctx.stroke();});
        canvas.addEventListener('mouseup',function(){drawing=false;document.getElementById('sig-data').value=canvas.toDataURL();});
        canvas.addEventListener('mouseleave',function(){if(drawing){document.getElementById('sig-data').value=canvas.toDataURL();}drawing=false;});
        canvas.addEventListener('touchstart',function(e){e.preventDefault();drawing=true;ctx.beginPath();var c=p(e);ctx.moveTo(c[0],c[1]);});
        canvas.addEventListener('touchmove',function(e){e.preventDefault();if(!drawing)return;var c=p(e);ctx.lineTo(c[0],c[1]);ctx.stroke();});
        canvas.addEventListener('touchend',function(){drawing=false;document.getElementById('sig-data').value=canvas.toDataURL();});
        document.getElementById('sig-clear').addEventListener('click',function(){ctx.clearRect(0,0,canvas.width,canvas.height);document.getElementById('sig-data').value='';});
        document.getElementById('sig-type').addEventListener('change',function(){
            var v=this.value;
            document.getElementById('sig-canvas-wrap').style.display = v==='digital' ? '' : 'none';
            document.getElementById('sig-text-wrap').style.display   = v==='text' ? '' : 'none';
            document.getElementById('sig-upload-wrap').style.display = v==='upload' ? '' : 'none';
            if (v !== 'digital') { document.getElementById('sig-data').value = ''; }
        });
        var form = canvas.closest('form');
        if (form) {
            form.addEventListener('submit', function(e) {
                var mode = document.getElementById('sig-type').value;
                if (mode === 'text') {
                    var textVal = document.getElementById('sig-text-input').value.trim();
                    document.getElementById('sig-data').value = textVal;
                } else if (mode === 'digital') {
                    var dataUrl = canvas.toDataURL();
                    var ctx2 = canvas.getContext('2d');
                    var imgData = ctx2.getImageData(0, 0, canvas.width, canvas.height).data;
                    var hasContent = false;
                    for (var i = 3; i < imgData.length; i += 4) {
                        if (imgData[i] !== 0) { hasContent = true; break; }
                    }
                    if (hasContent) {
                        document.getElementById('sig-data').value = dataUrl;
                    }
                }
            });
        }
    })();
    </script>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
