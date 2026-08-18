export default function TablePagination({
    page,
    pageCount,
    onPageChange,
}: {
    page: number;
    pageCount: number;
    onPageChange: (page: number) => void;
}) {
    const pages = Array.from(
        { length: pageCount },
        (_, index) => index + 1,
    );

    return (
        <div className="records-pagination">
            <button
                type="button"
                className="records-page-button"
                disabled={page === 1}
                onClick={() => onPageChange(1)}
            >
                «
            </button>
            <button
                type="button"
                className="records-page-button"
                disabled={page === 1}
                onClick={() => onPageChange(page - 1)}
            >
                ‹
            </button>

            {pages.map((item) => (
                <button
                    key={item}
                    type="button"
                    className={[
                        'records-page-button',
                        item === page ? 'active' : '',
                    ]
                        .filter(Boolean)
                        .join(' ')}
                    onClick={() => onPageChange(item)}
                >
                    {item}
                </button>
            ))}

            <button
                type="button"
                className="records-page-button"
                disabled={page === pageCount}
                onClick={() => onPageChange(page + 1)}
            >
                ›
            </button>
            <button
                type="button"
                className="records-page-button"
                disabled={page === pageCount}
                onClick={() => onPageChange(pageCount)}
            >
                »
            </button>
        </div>
    );
}
