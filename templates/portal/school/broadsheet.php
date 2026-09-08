<?php
/**
 * School area broadsheet — delegates to the exams area's broadsheet template.
 *
 * The broadsheet template was originally built at templates/portal/exams/broadsheet.php
 * because broadsheets were under the Examinations area. The school area also routes
 * to broadsheet (see PortalRouter::sections()['school']['broadsheet']), but the router
 * looks for templates/portal/{$area}/{$file}.php — so /portal/school/broadsheet/ was
 * falling through to fallback.php ("not been built yet") even though the broadsheet
 * worked perfectly under /portal/exams/broadsheet/. This file simply loads the
 * existing template.
 *
 * @var array<string,mixed> $educbt
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require EDUCBT_PRO_PATH . 'templates/portal/exams/broadsheet.php';
