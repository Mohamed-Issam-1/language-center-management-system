import { ChevronDown } from 'lucide-react';

export default function RecordsToolbar({
    search,
    onSearchChange,
    onExport,
}: {
    search: string;
    onSearchChange: (value: string) => void;
    onExport: () => void;
}) {
    return (
        <div className="records-toolbar">
            <button
                type="button"
                className="records-export-button"
                onClick={onExport}
            >
                Export
                <ChevronDown size={13} />
            </button>

            <label className="records-search">
                <span>Search:</span>
                <input
                    type="search"
                    className="records-search-input"
                    value={search}
                    onChange={(event) =>
                        onSearchChange(event.target.value)
                    }
                    placeholder="Filter rows..."
                />
            </label>
        </div>
    );
}
