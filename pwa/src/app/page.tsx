"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { LayoutDashboard } from "lucide-react";
import { Navbar } from "@/app/_components/Navbar";
import { Footer } from "@/app/_components/Footer";
import { FeatureCard } from "@/app/_components/FeatureCard";

export default function LandingPage() {
  const router = useRouter();
  const [loading, setLoading] = useState(false);

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

          <div className="landing-page__features grid grid-cols-1 gap-8 max-w-md mx-auto">
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
          </div>
        </section>
      </main>

      <Footer />
    </div>
  );
}
