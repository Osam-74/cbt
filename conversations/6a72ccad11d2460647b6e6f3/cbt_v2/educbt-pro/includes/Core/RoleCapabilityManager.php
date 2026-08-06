<?php

namespace EduCBTPro\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RoleCapabilityManager {
    /**
     * Returns EduCBT custom role slugs and labels.
     *
     * @return array<string,string>
     */
    public static function get_custom_roles(): array {
        return [
            'educbt_super_administrator' => 'Super Administrator',
            'educbt_school_administrator' => 'School Administrator',
            'educbt_principal' => 'Principal',
            'educbt_vice_principal' => 'Vice Principal',
            'educbt_examination_officer' => 'Examination Officer',
            'educbt_teacher' => 'Teacher',
            'educbt_parent' => 'Parent',
            'educbt_student' => 'Student',
        ];
    }

    /**
     * Returns the custom capabilities used by the plugin.
     *
     * @return array<int,string>
     */
    public static function get_custom_capabilities(): array {
        return [
            'educbt_view_students',
            'educbt_manage_students',
            'educbt_view_teachers',
            'educbt_manage_teachers',
            'educbt_view_results',
            'educbt_manage_results',
            'educbt_manage_exams',
            'educbt_parent_portal',
            'educbt_student_portal',
        ];
    }

    /**
     * Returns role to capability mappings.
     *
     * @return array<string,array<int,string>>
     */
    public static function get_role_capability_map(): array {
        return [
            'administrator' => self::get_custom_capabilities(),
            'educbt_super_administrator' => array_merge(
                self::get_custom_capabilities(),
                [ 'manage_options', 'list_users', 'edit_users', 'promote_users' ]
            ),
            'educbt_school_administrator' => array_merge(
                self::get_custom_capabilities(),
                [ 'read', 'list_users', 'edit_users' ]
            ),
            'educbt_principal' => [
                'read',
                'educbt_view_students',
                'educbt_view_teachers',
                'educbt_view_results',
                'educbt_manage_results',
                'educbt_manage_exams',
            ],
            'educbt_vice_principal' => [
                'read',
                'educbt_view_students',
                'educbt_view_teachers',
                'educbt_view_results',
                'educbt_manage_results',
            ],
            'educbt_examination_officer' => [
                'read',
                'educbt_view_students',
                'educbt_view_results',
                'educbt_manage_results',
                'educbt_manage_exams',
            ],
            'educbt_teacher' => [
                'read',
                'educbt_view_students',
                'educbt_view_results',
                'educbt_manage_exams',
            ],
            'educbt_parent' => [
                'read',
                'educbt_parent_portal',
            ],
            'educbt_student' => [
                'read',
                'educbt_student_portal',
            ],
            'editor'        => [
                'educbt_view_students',
                'educbt_manage_students',
                'educbt_view_teachers',
                'educbt_manage_teachers',
                'educbt_view_results',
                'educbt_manage_results',
                'educbt_manage_exams',
            ],
            'author'        => [
                'educbt_view_students',
                'educbt_view_teachers',
                'educbt_view_results',
            ],
            'subscriber'    => [
                'educbt_parent_portal',
                'educbt_student_portal',
            ],
        ];
    }

    public static function apply_role_capabilities(): void {
        foreach ( self::get_custom_roles() as $role_slug => $label ) {
            if ( ! get_role( $role_slug ) ) {
                add_role( $role_slug, $label, [ 'read' => true ] );
            }
        }

        foreach ( self::get_role_capability_map() as $role_name => $capabilities ) {
            $role = get_role( $role_name );
            if ( ! $role ) {
                continue;
            }

            foreach ( $capabilities as $capability ) {
                $role->add_cap( $capability );
            }
        }
    }

    public static function remove_role_capabilities(): void {
        $custom_capabilities = self::get_custom_capabilities();

        foreach ( self::get_role_capability_map() as $role_name => $caps ) {
            unset( $caps );

            $role = get_role( $role_name );
            if ( ! $role ) {
                continue;
            }

            foreach ( $custom_capabilities as $capability ) {
                $role->remove_cap( $capability );
            }
        }
    }
}
