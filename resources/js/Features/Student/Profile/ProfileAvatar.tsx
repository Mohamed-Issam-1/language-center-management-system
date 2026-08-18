export function initials(name: string) {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}

export default function ProfileAvatar({
    name,
    photoDataUrl,
    showStatus = false,
    className = 'profile-avatar',
}: {
    name: string;
    photoDataUrl?: string;
    showStatus?: boolean;
    className?: string;
}) {
    return (
        <div
            className={[
                className,
                photoDataUrl ? 'has-image' : '',
            ]
                .filter(Boolean)
                .join(' ')}
        >
            {photoDataUrl ? (
                <img src={photoDataUrl} alt="" />
            ) : (
                initials(name)
            )}

            {showStatus && (
                <span className="profile-avatar-status" />
            )}
        </div>
    );
}
