"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { dateTimeProvider } from "@/context/shared/infrastructure/DateTimeProvider";
import { CheckHealth } from "@/context/frontoffice/health/application/CheckHealth";
import { Activity, LayoutDashboard } from "lucide-react";
import { Navbar } from "@/app/_components/Navbar";
import { Footer } from "@/app/_components/Footer";
import { FeatureCard } from "@/app/_components/FeatureCard";

export default function LandingPage() {
  const router = useRouter();
  const [healthStatus, setHealthStatus] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const checkHealth = async () => {
    setLoading(true);
    try {
      const useCase = container.get<CheckHealth>("FrontOfficeCheckHealth");
      const result = await useCase.run();
      setHealthStatus(
        `Status: ${result.status} | Service: ${result.service} | Date: ${dateTimeProvider.formatIsoToLocalDateTime(result.datetime)}`,
      );
    } catch (error) {
      const detail = error instanceof Error ? error.message : "unknown error";
      setHealthStatus(`Error checking health: ${detail}`);
    } finally {
      setLoading(false);
    }
  };

  const goToBackOffice = () => {
    setLoading(true);
    setTimeout(() => {
      router.push("/backoffice");
    }, 800);
  };

  return (
    <div className="landing-page min-h-screen flex flex-col bg-slate-50 font-sans">
      <Navbar onGetStarted={goToBackOffice} />

      {/* Main Section */}
      <main className="landing-page__main flex-grow">
        <section className="landing-page__hero max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 md:py-24">
          <div className="landing-page__hero-content text-center mb-16">
            <h1 className="landing-page__title text-4xl md:text-6xl font-extrabold text-slate-900 mb-6 tracking-tight animate-in fade-in-0 slide-in-from-bottom-4 duration-700">
              Modern ERP for <span className="text-blue-600">Construction</span>
            </h1>
            <p
              className="landing-page__subtitle text-lg md:text-xl text-slate-600 max-w-2xl mx-auto animate-in fade-in-0 slide-in-from-bottom-4 duration-700"
              style={{ animationDelay: "100ms", animationFillMode: "both" }}
            >
              Streamline your projects, manage your workforce, and track every brick with Erpify.
              The all-in-one solution for construction management.
            </p>
          </div>

          <div className="landing-page__features grid grid-cols-1 md:grid-cols-2 gap-8 max-w-4xl mx-auto">
            <FeatureCard
              title="Admin BackOffice"
              description="Access the powerful dashboard to manage your entire construction operation."
              icon={LayoutDashboard}
              iconColor="text-blue-600"
              iconBg="bg-blue-50"
              buttonText="Go to BackOffice"
              buttonVariant="default"
              onClick={goToBackOffice}
              loading={loading}
            />

            <FeatureCard
              title="FrontOffice API"
              description="Verify the status of our core services and ensure everything is running smoothly."
              icon={Activity}
              iconColor="text-emerald-600"
              iconBg="bg-emerald-50"
              buttonText="Check FrontOffice API health"
              buttonVariant="secondary"
              onClick={checkHealth}
              loading={loading}
            >
              {healthStatus && (
                <div
                  data-testid="frontoffice-health-status"
                  className="landing-page__health-status mt-6 p-4 bg-slate-50 rounded-xl text-sm font-mono text-slate-600 border border-slate-200 w-full animate-in fade-in-0 slide-in-from-top-1 duration-300"
                >
                  {healthStatus}
                </div>
              )}
            </FeatureCard>
          </div>
        </section>
      </main>

      <Footer />
    </div>
  );
}
