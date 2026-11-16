<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('service_orders')) {
            Schema::table('service_orders', function (Blueprint $table): void {
                if (! Schema::hasIndex('service_orders', 'so_created_at_idx')) {
                    $table->index('created_at', 'so_created_at_idx');
                }
                if (! Schema::hasIndex('service_orders', 'so_service_created_idx')) {
                    $table->index(['service_id', 'created_at'], 'so_service_created_idx');
                }
                if (! Schema::hasIndex('service_orders', 'so_completed_at_idx')) {
                    $table->index('completed_at', 'so_completed_at_idx');
                }
            });
        }

        if (Schema::hasTable('estimates')) {
            Schema::table('estimates', function (Blueprint $table): void {
                if (! Schema::hasIndex('estimates', 'est_created_at_idx')) {
                    $table->index('created_at', 'est_created_at_idx');
                }
                if (! Schema::hasIndex('estimates', 'est_status_created_idx')) {
                    $table->index(['status', 'created_at'], 'est_status_created_idx');
                }
            });
        }

        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table): void {
                if (! Schema::hasIndex('invoices', 'inv_issue_status_idx')) {
                    $table->index(['issue_date', 'status'], 'inv_issue_status_idx');
                }
            });
        }

        if (Schema::hasTable('technician_work_sessions')) {
            Schema::table('technician_work_sessions', function (Blueprint $table): void {
                if (! Schema::hasIndex('technician_work_sessions', 'tws_tech_status_completed_idx')) {
                    $table->index(['technician_id', 'status', 'completed_at'], 'tws_tech_status_completed_idx');
                }
            });
        }

        if (Schema::hasTable('warranties')) {
            Schema::table('warranties', function (Blueprint $table): void {
                if (! Schema::hasIndex('warranties', 'war_created_at_idx')) {
                    $table->index('created_at', 'war_created_at_idx');
                }
            });
        }

        if (Schema::hasTable('warranty_claims')) {
            Schema::table('warranty_claims', function (Blueprint $table): void {
                if (! Schema::hasIndex('warranty_claims', 'wc_submitted_at_idx')) {
                    $table->index('submitted_at', 'wc_submitted_at_idx');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('service_orders')) {
            Schema::table('service_orders', function (Blueprint $table): void {
                if (Schema::hasIndex('service_orders', 'so_created_at_idx')) {
                    $table->dropIndex('so_created_at_idx');
                }
                if (Schema::hasIndex('service_orders', 'so_service_created_idx')) {
                    $table->dropIndex('so_service_created_idx');
                }
                if (Schema::hasIndex('service_orders', 'so_completed_at_idx')) {
                    $table->dropIndex('so_completed_at_idx');
                }
            });
        }

        if (Schema::hasTable('estimates')) {
            Schema::table('estimates', function (Blueprint $table): void {
                if (Schema::hasIndex('estimates', 'est_created_at_idx')) {
                    $table->dropIndex('est_created_at_idx');
                }
                if (Schema::hasIndex('estimates', 'est_status_created_idx')) {
                    $table->dropIndex('est_status_created_idx');
                }
            });
        }

        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table): void {
                if (Schema::hasIndex('invoices', 'inv_issue_status_idx')) {
                    $table->dropIndex('inv_issue_status_idx');
                }
            });
        }

        if (Schema::hasTable('technician_work_sessions')) {
            Schema::table('technician_work_sessions', function (Blueprint $table): void {
                if (Schema::hasIndex('technician_work_sessions', 'tws_tech_status_completed_idx')) {
                    $table->dropIndex('tws_tech_status_completed_idx');
                }
            });
        }

        if (Schema::hasTable('warranties')) {
            Schema::table('warranties', function (Blueprint $table): void {
                if (Schema::hasIndex('warranties', 'war_created_at_idx')) {
                    $table->dropIndex('war_created_at_idx');
                }
            });
        }

        if (Schema::hasTable('warranty_claims')) {
            Schema::table('warranty_claims', function (Blueprint $table): void {
                if (Schema::hasIndex('warranty_claims', 'wc_submitted_at_idx')) {
                    $table->dropIndex('wc_submitted_at_idx');
                }
            });
        }
    }
};
