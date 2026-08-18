import ProfileAvatar from './ProfileAvatar';
import { Camera } from 'lucide-react';
import { ChangeEvent, useRef, useState } from 'react';

const MAX_SIZE = 2 * 1024 * 1024;

export default function ProfilePhotoEditor({
    name,
    photoDataUrl,
    onPhotoChange,
}: {
    name: string;
    photoDataUrl?: string;
    onPhotoChange: (dataUrl?: string) => void;
}) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [error, setError] = useState('');

    const pickPhoto = () => inputRef.current?.click();

    const changePhoto = (
        event: ChangeEvent<HTMLInputElement>,
    ) => {
        const file = event.target.files?.[0];

        if (!file) {
            return;
        }

        if (!['image/jpeg', 'image/png', 'image/gif'].includes(file.type)) {
            setError('Use a JPG, PNG, or GIF image.');
            return;
        }

        if (file.size > MAX_SIZE) {
            setError('Image must be 2 MB or smaller.');
            return;
        }

        const reader = new FileReader();

        reader.onload = () => {
            const value =
                typeof reader.result === 'string'
                    ? reader.result
                    : undefined;

            onPhotoChange(value);
            setError('');
        };

        reader.readAsDataURL(file);
    };

    return (
        <section className="profile-card edit-photo-card">
            <h2 className="profile-section-title">
                Profile Photo
            </h2>

            <div className="edit-photo-content">
                <ProfileAvatar
                    name={name}
                    photoDataUrl={photoDataUrl}
                    className="edit-photo-avatar"
                />

                <div className="edit-photo-actions">
                    <input
                        ref={inputRef}
                        type="file"
                        accept="image/jpeg,image/png,image/gif"
                        className="hidden"
                        onChange={changePhoto}
                    />

                    <button
                        type="button"
                        className="edit-upload-button"
                        onClick={pickPhoto}
                    >
                        <Camera size={16} />
                        Upload Photo
                    </button>

                    <p className="edit-photo-help">
                        JPG, PNG or GIF · Max 2 MB
                    </p>

                    {error && (
                        <p className="edit-photo-error">
                            {error}
                        </p>
                    )}
                </div>
            </div>
        </section>
    );
}
