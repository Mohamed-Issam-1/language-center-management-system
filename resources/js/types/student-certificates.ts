export type CertificateAccent =
    | 'green'
    | 'cyan';

export type StudentCertificate = {
    id: number;

    courseName: string;
    level: string;

    certificateNumber: string;
    issuedAt: string;

    grade: string;
    attendance: number;

    accent: CertificateAccent;
};

export type StudentCertificatesData = {
    certificates: StudentCertificate[];
};