<?php
/**
 * A class teacher registering into their own class.
 *
 * Deliberately the same template as the school office uses. The scope layer already
 * narrows the class dropdown and the SQL to the classes this teacher holds, so a
 * second near-identical screen would only be a second place for that rule to drift.
 *
 * @var array<string,mixed> $educbt
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require EDUCBT_PRO_PATH . 'templates/portal/school/students.php';
