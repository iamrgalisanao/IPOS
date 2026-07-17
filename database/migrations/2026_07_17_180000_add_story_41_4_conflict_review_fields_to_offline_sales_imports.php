<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offline_sales_imports', function (Blueprint $table) {
            $table->string('conflict_family')->nullable()->after('review_reason');
            $table->string('reason_code')->nullable()->after('conflict_family');
            $table->string('review_severity')->nullable()->after('reason_code');
            $table->string('retry_classification')->nullable()->after('review_severity');
            $table->string('suggested_action_code')->nullable()->after('retry_classification');
            $table->string('cash_exposure_status')->nullable()->after('cash_status');
            $table->json('conflict_metadata')->nullable()->after('current_consequence_status');
            $table->uuid('predecessor_offline_transaction_uuid')->nullable()->after('conflict_metadata');
            $table->string('predecessor_dependency')->nullable()->after('predecessor_offline_transaction_uuid');
            $table->timestamp('sequence_gap_detected_at')->nullable()->after('predecessor_dependency');
            $table->timestamp('sequence_gap_grace_expires_at')->nullable()->after('sequence_gap_detected_at');
            $table->string('sequence_gap_state')->nullable()->after('sequence_gap_grace_expires_at');
            $table->string('missing_sequence_from')->nullable()->after('sequence_gap_state');
            $table->string('missing_sequence_to')->nullable()->after('missing_sequence_from');
            $table->timestamp('predecessor_lookup_last_attempt_at')->nullable()->after('missing_sequence_to');
            $table->unsignedSmallInteger('duplicate_score')->nullable()->after('predecessor_lookup_last_attempt_at');
            $table->unsignedSmallInteger('duplicate_review_threshold')->nullable()->after('duplicate_score');
            $table->json('duplicate_rule_ids')->nullable()->after('duplicate_review_threshold');
            $table->json('duplicate_candidates')->nullable()->after('duplicate_rule_ids');
            $table->uuid('duplicate_candidate_sale_id')->nullable()->after('duplicate_candidates');
            $table->uuid('duplicate_candidate_import_id')->nullable()->after('duplicate_candidate_sale_id');
            $table->string('duplicate_detection_version')->nullable()->after('duplicate_candidate_import_id');
            $table->string('conflict_policy_version')->nullable()->after('duplicate_detection_version');
            $table->string('ordering_policy_version')->nullable()->after('conflict_policy_version');
            $table->string('review_payload_schema_version')->nullable()->after('ordering_policy_version');
            $table->string('time_evidence_status')->nullable()->after('review_payload_schema_version');
            $table->string('business_date_status')->nullable()->after('time_evidence_status');
            $table->date('proposed_business_date')->nullable()->after('business_date_status');
            $table->date('resolved_business_date')->nullable()->after('proposed_business_date');
            $table->string('business_date_review_reason')->nullable()->after('resolved_business_date');
            $table->timestamp('review_locked_at')->nullable()->after('review_required_at');
            $table->timestamp('review_opened_at')->nullable()->after('review_locked_at');
            $table->timestamp('review_due_at')->nullable()->after('review_opened_at');
            $table->string('review_sla_policy_id')->nullable()->after('review_due_at');
            $table->string('review_sla_policy_version')->nullable()->after('review_sla_policy_id');
            $table->unsignedSmallInteger('review_escalation_level')->nullable()->after('review_sla_policy_version');
            $table->timestamp('last_review_activity_at')->nullable()->after('review_escalation_level');
            $table->string('assigned_team')->nullable()->after('last_review_activity_at');
            $table->json('review_decision_snapshot')->nullable()->after('assigned_team');
            $table->string('current_resolution_status')->nullable()->after('review_decision_snapshot');

            $table->index(
                ['tenant_id', 'sales_machine_profile_id', 'terminal_binding_epoch', 'sequence_gap_state'],
                'osi_41_4_sequence_gap_idx'
            );
            $table->index(['tenant_id', 'branch_id', 'review_due_at'], 'osi_41_4_review_due_idx');
            $table->index(['tenant_id', 'branch_id', 'assigned_team', 'review_escalation_level'], 'osi_41_4_review_team_idx');
        });
    }

    public function down(): void
    {
        Schema::table('offline_sales_imports', function (Blueprint $table) {
            $table->dropIndex('osi_41_4_sequence_gap_idx');
            $table->dropIndex('osi_41_4_review_due_idx');
            $table->dropIndex('osi_41_4_review_team_idx');

            $table->dropColumn([
                'conflict_family',
                'reason_code',
                'review_severity',
                'retry_classification',
                'suggested_action_code',
                'cash_exposure_status',
                'conflict_metadata',
                'predecessor_offline_transaction_uuid',
                'predecessor_dependency',
                'sequence_gap_detected_at',
                'sequence_gap_grace_expires_at',
                'sequence_gap_state',
                'missing_sequence_from',
                'missing_sequence_to',
                'predecessor_lookup_last_attempt_at',
                'duplicate_score',
                'duplicate_review_threshold',
                'duplicate_rule_ids',
                'duplicate_candidates',
                'duplicate_candidate_sale_id',
                'duplicate_candidate_import_id',
                'duplicate_detection_version',
                'conflict_policy_version',
                'ordering_policy_version',
                'review_payload_schema_version',
                'time_evidence_status',
                'business_date_status',
                'proposed_business_date',
                'resolved_business_date',
                'business_date_review_reason',
                'review_locked_at',
                'review_opened_at',
                'review_due_at',
                'review_sla_policy_id',
                'review_sla_policy_version',
                'review_escalation_level',
                'last_review_activity_at',
                'assigned_team',
                'review_decision_snapshot',
                'current_resolution_status',
            ]);
        });
    }
};
