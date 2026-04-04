"use client";

import { useState } from "react";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { CheckHealth } from "@/context/backoffice/health/application/CheckHealth";
import { motion } from "motion/react";
import { Activity, ShieldCheck } from "lucide-react";
import { Button } from "@/context/shared/infrastructure/ui/components/atoms/Button";

export default function HealthPage() {
  const [healthStatus, setHealthStatus] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const checkHealth = async () => {
    setLoading(true);
    try {
      const useCase = container.get<CheckHealth>("BackOfficeCheckHealth");
      const result = await useCase.run();
      setHealthStatus(
        `Status: ${result.status} | Service: ${result.service} | Date: ${new Date(result.datetime).toLocaleString()}`,
      );
    } catch (_error) {
      setHealthStatus("Error checking health");
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="health-page space-y-10">
      <header className="health-page__header flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div className="health-page__header-info">
          <h1 className="health-page__title text-3xl font-extrabold text-slate-900 tracking-tight">
            System Health
          </h1>
          <p className="health-page__subtitle text-slate-500 font-medium mt-1">
            Monitor and verify the status of your BackOffice API services.
          </p>
        </div>
      </header>

      <div className="health-page__content bg-white p-8 rounded-3xl border border-slate-100 shadow-sm space-y-8">
        <div className="health-page__status-card flex flex-col items-center justify-center text-center p-12 bg-slate-50 rounded-2xl border border-slate-100">
          <div className="bg-blue-100 p-4 rounded-full mb-6">
            <ShieldCheck className="w-10 h-10 text-blue-600" />
          </div>
          <h2 className="text-xl font-bold text-slate-900 mb-2">API Connectivity</h2>
          <p className="text-slate-500 max-w-md mb-8">
            Perform a real-time health check to ensure all backend services are responding
            correctly.
          </p>

          <Button
            variant="primary"
            size="lg"
            onClick={checkHealth}
            loading={loading}
            className="health-page__button shadow-lg shadow-blue-200 px-8"
          >
            <Activity className={`w-5 h-5 mr-2 ${loading ? "animate-pulse" : ""}`} />
            {loading ? "Checking API..." : "Run Health Check"}
          </Button>
        </div>

        {healthStatus && (
          <motion.div
            initial={{ opacity: 0, scale: 0.95 }}
            animate={{ opacity: 1, scale: 1 }}
            className="health-page__result p-6 bg-emerald-50 border border-emerald-100 rounded-2xl text-sm font-mono text-emerald-700 flex items-center gap-4"
          >
            <div className="w-3 h-3 bg-emerald-500 rounded-full animate-ping shrink-0" />
            <div className="flex flex-col gap-1">
              <span className="font-bold uppercase text-[10px] tracking-widest text-emerald-600">
                Response Received
              </span>
              {healthStatus}
            </div>
          </motion.div>
        )}
      </div>
    </div>
  );
}
