import type { GradeResult } from '@/types/student-grades';

export default function GradeSummary({
    results,
}: {
    results: GradeResult[];
}) {
    const published =
        results.filter(
            (result) =>
                result.status === 'published' &&
                result.percentage !== null,
        );

    const pending =
        results.filter(
            (result) =>
                result.status === 'pending',
        );

    const average =
        published.length > 0
            ? Math.round(
                  published.reduce(
                      (total, result) =>
                          total +
                          (result.percentage ?? 0),
                      0,
                  ) / published.length,
              )
            : null;

    const averageTone =
        average !== null && average < 85
            ? 'orange'
            : 'cyan';

    return (
        <div className="grades-summary">
            <article className="academic-summary-card">
                <p className="academic-summary-label">
                    Overall Average
                </p>

                <p
                    className={`academic-summary-value ${averageTone}`}
                >
                    {average === null
                        ? '—'
                        : `${average}%`}
                </p>
            </article>

            <article className="academic-summary-card">
                <p className="academic-summary-label">
                    Published Results
                </p>

                <p className="academic-summary-value blue">
                    {published.length}
                </p>
            </article>

            <article className="academic-summary-card">
                <p className="academic-summary-label">
                    Pending Results
                </p>

                <p className="academic-summary-value orange">
                    {pending.length}
                </p>
            </article>
        </div>
    );
}