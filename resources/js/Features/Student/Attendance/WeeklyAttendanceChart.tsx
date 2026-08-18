import type { WeeklyAttendance } from '@/types/student-attendance';

export default function WeeklyAttendanceChart({
    data,
}: {
    data: WeeklyAttendance[];
}) {
    const max = 4;

    return (
        <section className="records-card records-card-pad">
            <h2 className="records-heading">
                Weekly Attendance — Last 8 Weeks
            </h2>

            <div className="weekly-chart">
                <div className="weekly-chart-body">
                    <div className="weekly-chart-y">
                        {[0, 1, 2, 3, 4].map((value) => (
                            <span key={value}>{value}</span>
                        ))}
                    </div>

                    <div className="weekly-chart-grid">
                        {[0, 1, 2, 3, 4].map((value) => (
                            <span
                                key={value}
                                className="weekly-chart-grid-line"
                            />
                        ))}
                    </div>

                    <div className="weekly-chart-bars">
                        {data.map((week) => (
                            <div
                                key={week.week}
                                className="weekly-chart-group"
                            >
                                <span
                                    className="weekly-bar present"
                                    style={{
                                        height: `${(week.present / max) * 100}%`,
                                    }}
                                />
                                <span
                                    className="weekly-bar absent"
                                    style={{
                                        height: `${(week.absent / max) * 100}%`,
                                    }}
                                />
                            </div>
                        ))}
                    </div>

                    <div className="weekly-chart-x">
                        {data.map((week) => (
                            <span key={week.week}>{week.week}</span>
                        ))}
                    </div>
                </div>

                <div className="weekly-legend">
                    <span className="weekly-legend-item">
                        <span className="weekly-legend-box present" />
                        Present
                    </span>
                    <span className="weekly-legend-item">
                        <span className="weekly-legend-box absent" />
                        Absent
                    </span>
                </div>
            </div>
        </section>
    );
}
