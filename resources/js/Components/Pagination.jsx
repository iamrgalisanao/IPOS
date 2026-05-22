import React from 'react';
import { Link } from '@inertiajs/react';

export default function Pagination({ links }) {
    return (
        links.length > 3 && (
            <div className="flex flex-wrap -mb-1">
                {links.map((link, key) => (
                    link.url === null ? (
                        <div
                            key={key}
                            className="mr-1 mb-1 px-4 py-3 text-sm leading-4 text-gray-400 border rounded-xl"
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ) : (
                        <Link
                            key={key}
                            className={`mr-1 mb-1 px-4 py-3 text-sm leading-4 border rounded-xl hover:bg-white focus:border-indigo-500 focus:text-indigo-500 transition-all ${link.active ? 'bg-indigo-600 text-white hover:bg-indigo-700' : ''}`}
                            href={link.url}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    )
                ))}
            </div>
        )
    );
}
