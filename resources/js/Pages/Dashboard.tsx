import ActiveCoursesCard from "@/Features/Student/Dashboard/ActiveCoursesCard";
import AttendanceOverview from "@/Features/Student/Dashboard/AttendanceOverview";
import AttendanceWarning from "@/Features/Student/Dashboard/AttendanceWarning";
import DashboardStats from "@/Features/Student/Dashboard/DashboardStats";
import FinancialSummary from "@/Features/Student/Dashboard/FinancialSummary";
import NextSessionCard from "@/Features/Student/Dashboard/NextSessionCard";
import TodayScheduleCard from "@/Features/Student/Dashboard/TodayScheduleCard";
import WelcomeBanner from "@/Features/Student/Dashboard/WelcomeBanner";
import StudentLayout from "@/Layouts/StudentLayout";
import type { PageProps } from "@/types";
import type { StudentDashboardData } from "@/types/student-dashboard";
import { Head, usePage } from "@inertiajs/react";

const demoDashboardData: StudentDashboardData = {
  student: {
    name: "Mohammad Znaid",
    studentId: "STU-2024-0842",
  },
  center: {
    name: "Al-Hilal Language Center",
    branch: "Riyadh – Main Branch",
  },
  greeting: {
    dateLabel: "Monday, November 18, 2024",
    title: "Good evening, Mohammad!",
  },
  stats: [
    {
      label: "Active Courses",
      value: "2",
      tone: "blue",
      icon: "courses",
    },
    {
      label: "Avg Attendance",
      value: "92%",
      tone: "cyan",
      icon: "attendance",
    },
    {
      label: "Total Paid",
      value: "SAR 2,000",
      tone: "cyan",
      icon: "paid",
    },
    {
      label: "Outstanding",
      value: "SAR 900",
      tone: "red",
      icon: "outstanding",
    },
  ],
  courses: [
    {
      code: "B2",
      title: "English – Intermediate",
      teacher: "Mr. Hussam Al-Attar",
      schedule: "Mon, Wed · 5:00 – 7:00 PM",
      attendance: 88,
      accent: "indigo",
    },
    {
      code: "A1",
      title: "French – Beginner",
      teacher: "Ms. Leila Mansouri",
      schedule: "Tue, Thu · 6:00 – 8:00 PM",
      attendance: 95,
      accent: "violet",
    },
  ],
  nextSession: {
    course: "English – Intermediate",
    teacher: "Mr. Hussam Al-Attar",
    room: "Room 204",
    time: "Today · 5:00 PM – 7:00 PM",
  },
  todaySchedule: [
    {
      course: "English – Intermediate",
      teacher: "Mr. Hussam Al-Attar",
      room: "Room 204",
      time: "5:00 PM – 7:00 PM",
    },
  ],
  todayScheduleLabel: "Mon, Nov 18",
  attendance: {
    average: 92,
    totalAbsent: 4,
    maximumAllowedAbsences: 6,
    warningText:
      "You have 4 absences across all courses. The maximum allowed is 6. Exceeding the limit may affect your enrollment status.",
  },
  financial: {
    currency: "SAR",
    totalFees: 2900,
    totalPaid: 2000,
    remaining: 900,
    overdue: 600,
    nextInstallment: 400,
    nextInstallmentCourse: "French – Beginner",
    nextInstallmentDue: "Dec 1, 2024",
  },
};

type DashboardPageProps = PageProps & {
  dashboard?: StudentDashboardData;
};

export default function Dashboard() {
  const page = usePage<DashboardPageProps>();

  const demoMode = page.url.startsWith("/demo");

  const dashboard = demoMode ? demoDashboardData : page.props.dashboard;

  if (!dashboard) {
    throw new Error("Student dashboard data was not provided by Laravel.");
  }

  return (
    <StudentLayout
      studentName={dashboard.student.name}
      studentId={dashboard.student.studentId}
      centerName={dashboard.center.name}
      branchName={dashboard.center.branch}
      pageTitle="Dashboard"
      activeNav="dashboard"
      fluid
    >
      <Head title="Dashboard" />

      <div className="space-y-4 lg:space-y-5">
        <WelcomeBanner
          dateLabel={dashboard.greeting.dateLabel}
          title={dashboard.greeting.title}
          centerName={dashboard.center.name}
          branchName={dashboard.center.branch}
        />

        <DashboardStats stats={dashboard.stats} />

        <div className="grid gap-4 lg:grid-cols-[1.05fr_0.95fr] lg:items-start lg:gap-5">
          <div className="space-y-4 lg:space-y-5">
            <ActiveCoursesCard courses={dashboard.courses} />

            <NextSessionCard session={dashboard.nextSession} />

            <TodayScheduleCard
              sessions={dashboard.todaySchedule}
              dateLabel={dashboard.todayScheduleLabel}
            />
          </div>

          <div className="space-y-4 lg:space-y-5">
            <AttendanceWarning warningText={dashboard.attendance.warningText} />

            <AttendanceOverview
              average={dashboard.attendance.average}
              totalAbsent={dashboard.attendance.totalAbsent}
              courses={dashboard.courses}
            />

            <FinancialSummary data={dashboard.financial} />
          </div>
        </div>
      </div>
    </StudentLayout>
  );
}
