import type { StudentCertificate } from '@/types/student-certificates';
import {
    Download,
    Star,
} from 'lucide-react';

export default function CertificateCard({
    certificate,
    onDownload,
}: {
    certificate: StudentCertificate;
    onDownload: (
        certificateNumber: string,
    ) => void;
}) {
    return (
        <article
            className={`certificate-card ${certificate.accent}`}
        >
            <div
                className={`certificate-icon ${certificate.accent}`}
            >
                <Star
                    size={24}
                    strokeWidth={2}
                />
            </div>

            <div className="certificate-main">
                <h2 className="certificate-course">
                    {
                        certificate.courseName
                    }
                </h2>

                <p className="certificate-level">
                    {certificate.level}
                </p>

                <div className="certificate-details">
                    <div>
                        <span className="certificate-detail-label">
                            Certificate No.
                        </span>

                        <strong className="certificate-detail-value">
                            {
                                certificate.certificateNumber
                            }
                        </strong>
                    </div>

                    <div>
                        <span className="certificate-detail-label">
                            Issued
                        </span>

                        <strong className="certificate-detail-value">
                            {
                                certificate.issuedAt
                            }
                        </strong>
                    </div>

                    <div>
                        <span className="certificate-detail-label">
                            Grade
                        </span>

                        <strong className="certificate-detail-value">
                            {certificate.grade}
                        </strong>
                    </div>

                    <div>
                        <span className="certificate-detail-label">
                            Attendance
                        </span>

                        <strong className="certificate-detail-value">
                            {
                                certificate.attendance
                            }
                            %
                        </strong>
                    </div>
                </div>
            </div>

            <button
                type="button"
                className="certificate-download"
                onClick={() =>
                    onDownload(
                        certificate.certificateNumber,
                    )
                }
            >
                <Download
                    size={16}
                    strokeWidth={1.9}
                />

                Download PDF
            </button>
        </article>
    );
}