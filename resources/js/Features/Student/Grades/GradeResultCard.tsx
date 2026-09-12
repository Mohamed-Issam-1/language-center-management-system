import type { GradeResult } from '@/types/student-grades';
import { MessageCircle } from 'lucide-react';

function formatNumber(
    value: number,
) {
    return Number.isInteger(value)
        ? value.toString()
        : value.toFixed(2);
}

function gradeTone(
    grade: string,
) {
    if (
        grade
            .toUpperCase()
            .startsWith('A')
    ) {
        return 'cyan';
    }

    if (
        grade
            .toUpperCase()
            .startsWith('B')
    ) {
        return 'orange';
    }

    return 'blue';
}

export default function GradeResultCard({
    result,
}: {
    result: GradeResult;
}) {
    const published =
        result.status === 'published';

    return (
        <article
            className={`grade-result-card ${result.accent}`}
        >
            <div className="grade-result-main">
                <div className="grade-result-title-row">
                    <h2 className="grade-result-title">
                        {result.title}
                    </h2>

                    <span
                        className={`grade-type-badge ${result.accent}`}
                    >
                        {result.type}
                    </span>
                </div>

                <p className="grade-result-meta">
                    {result.courseName}
                    {' · '}
                    {result.date}
                </p>

                {published &&
                    result.comment && (
                        <p className="grade-result-comment">
                            <MessageCircle
                                size={13}
                                strokeWidth={
                                    1.7
                                }
                            />

                            {result.comment}
                        </p>
                    )}
            </div>

            <div className="grade-result-score">
                {published &&
                result.letterGrade ? (
                    <>
                        <strong
                            className={`grade-letter ${gradeTone(
                                result.letterGrade,
                            )}`}
                        >
                            {
                                result.letterGrade
                            }
                        </strong>

                        {result.score !==
                            null && (
                            <span className="grade-score-number">
                                {formatNumber(
                                    result.score,
                                )}
                                /
                                {formatNumber(
                                    result.maxScore,
                                )}
                            </span>
                        )}
                    </>
                ) : (
                    <span className="grade-pending">
                        Pending
                    </span>
                )}
            </div>
        </article>
    );
}