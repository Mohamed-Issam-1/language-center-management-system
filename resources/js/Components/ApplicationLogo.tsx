import { SVGAttributes } from 'react';

export default function ApplicationLogo(
    props: SVGAttributes<SVGElement>,
) {
    return (
        <svg
            {...props}
            viewBox="0 0 205 52"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
        >
            {/* Book / globe symbol */}

            <g
                stroke="currentColor"
                strokeWidth="2.4"
                strokeLinecap="round"
                strokeLinejoin="round"
            >
                <path d="M4 17C13 12 22 13 30 18V44C21 37 13 37 4 41V17Z" />

                <path d="M56 17C47 12 38 13 30 18V44C39 37 47 37 56 41V17Z" />

                <path d="M30 18V7" />

                <path d="M16 15C16 8 21.5 3 30 3C37.5 3 43 7.5 44 13" />

                <path d="M5 36C14 33 22 35 30 41" />

                <path d="M55 36C46 33 38 35 30 41" />

                <circle
                    cx="48"
                    cy="9"
                    r="3"
                    fill="currentColor"
                    stroke="none"
                />

                <path d="M39 16L48 9" />
            </g>

            {/* LCMS text */}

            <text
                x="66"
                y="38"
                fill="currentColor"
                fontFamily="Arial, Helvetica, sans-serif"
                fontSize="36"
                fontWeight="800"
                letterSpacing="-2"
            >
                LCMS
            </text>
        </svg>
    );
}