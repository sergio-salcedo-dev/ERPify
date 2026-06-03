"use client";

import { useRouter } from "next/navigation";
import { Navbar } from "@/app/_components/Navbar";
import { Footer } from "@/app/_components/Footer";

export default function LandingPage() {
  const router = useRouter();

  const goToBackOffice = () => {
    setTimeout(() => {
      router.push("/backoffice");
    }, 800);
  };

  return (
    <div className="landing-page min-h-screen flex flex-col bg-slate-50 font-sans">
      <Navbar goToBackoffice={goToBackOffice} />

      {/* Main Section */}
      <main className="landing-page__main flex-grow">
        <section className="landing-page__hero max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 md:py-24">
          <div className="landing-page__hero-content text-center mb-16">
            <h1 className="landing-page__title text-4xl md:text-6xl font-extrabold text-slate-900 mb-6 tracking-tight animate-in fade-in-0 slide-in-from-bottom-4 duration-700">
              ERP for <span className="text-blue-600">Construction</span>
            </h1>
          </div>
        </section>
      </main>

      <Footer />
    </div>
  );
}
