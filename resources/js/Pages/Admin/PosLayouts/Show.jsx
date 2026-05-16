import React from 'react';

export default function Show({ layout }) {
    return (
        <div className="p-6">
            <h1 className="text-2xl font-bold mb-4">POS Layout: {layout.name}</h1>
            <div className="bg-white p-6 rounded-lg shadow">
                <pre>{JSON.stringify(layout.schema, null, 2)}</pre>
            </div>
        </div>
    );
}
