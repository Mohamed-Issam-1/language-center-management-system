import '../../../css/student-academics.css';

import CertificateCard from '@/Features/Student/Certificates/CertificateCard';
import StudentLayout from '@/Layouts/StudentLayout';
import type {
    StudentCertificate,
    StudentCertificatesData,
} from '@/types/student-certificates';
import { Head } from '@inertiajs/react';
import {
    useEffect,
    useState,
} from 'react';

const certificates: StudentCertificate[] = [
    {
        id: 1,

        courseName:
            'English – Elementary',

        level:
            'A2 Elementary',

        certificateNumber:
            'LCMS-2026-0099',

        issuedAt:
            'Apr 20, 2026',

        grade: 'A',

        attendance: 94,

        accent: 'green',
    },
    {
        id: 2,

        courseName:
            'English – Pre-Elementary',

        level:
            'A1 Beginner',

        certificateNumber:
            'LCMS-2025-0052',

        issuedAt:
            'Dec 28, 2025',

        grade: 'B+',

        attendance: 90,

        accent: 'cyan',
    },
];

const demoCertificatesData: StudentCertificatesData = {
    certificates,
};

export default function Certificates() {
    const [
        message,
        setMessage,
    ] =
        useState<string | null>(
            null,
        );

    useEffect(() => {
        if (!message) {
            return;
        }

        const timeout =
            window.setTimeout(
                () => {
                    setMessage(
                        null,
                    );
                },
                2500,
            );

        return () =>
            window.clearTimeout(
                timeout,
            );
    }, [message]);

    const handleDownload = (
        certificateNumber: string,
    ) => {
        /*
         * UI is complete.
         *
         * Actual certificate generation/download must
         * come from the backend because ownership and
         * authorization cannot safely be enforced in
         * React.
         */
        setMessage(
            `PDF download for ${certificateNumber} is not connected yet.`,
        );
    };

    return (
        <StudentLayout
            studentName="Mohammad Demo Student"
            studentId="99000001"
            centerName="Beatty LLC Language Center"
            branchName="Main Branch"
            pageTitle="My Certificates"
            activeNav="certificates"
            fluid
        >
            <Head title="My Certificates" />

            <div className="student-academic-page certificates-page">
                <div className="certificate-list">
                    {demoCertificatesData.certificates.map(
                        (
                            certificate,
                        ) => (
                            <CertificateCard
                                key={
                                    certificate.id
                                }
                                certificate={
                                    certificate
                                }
                                onDownload={
                                    handleDownload
                                }
                            />
                        ),
                    )}
                </div>

                {message && (
                    <div
                        className="certificate-download-toast"
                        role="status"
                    >
                        {message}
                    </div>
                )}
            </div>
        </StudentLayout>
    );
}