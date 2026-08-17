import logoBlue from '@/Assets/Images/lcms-logo.svg';
import logoWhite from '@/Assets/Images/lcms-logo-white.svg';

interface ApplicationLogoProps {
    variant?: 'blue' | 'white';
    className?: string;
}

export default function ApplicationLogo({
    variant = 'blue',
    className = '',
}: ApplicationLogoProps) {
    return (
        <img
            src={variant === 'white' ? logoWhite : logoBlue}
            alt="LCMS"
            className={className}
        />
    );
}