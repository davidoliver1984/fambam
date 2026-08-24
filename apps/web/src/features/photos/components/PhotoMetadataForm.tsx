import { type SyntheticEvent, useState } from "react";

import type {
  DatePrecision,
  PhotoMetadataInput,
  PhotoMetadataProposal,
} from "../types/photo";

export function PhotoMetadataForm({
  pending,
  onSubmit,
}: {
  pending: boolean;
  onSubmit: (input: PhotoMetadataInput) => Promise<PhotoMetadataProposal>;
}) {
  const [field, setField] = useState<"historical_date" | "location">(
    "historical_date",
  );
  const [precision, setPrecision] = useState<DatePrecision>("exact");
  const [value, setValue] = useState("");
  const [location, setLocation] = useState("");
  const [clears, setClears] = useState(false);
  const [message, setMessage] = useState("");

  async function submit(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    const input: PhotoMetadataInput = { field };
    if (clears) input.clears_claim = true;
    else if (field === "historical_date") {
      input.date = { precision, value: precision === "unknown" ? null : value };
    } else input.location_description = location.trim();

    try {
      const result = await onSubmit(input);
      setMessage(
        result.status === "approved"
          ? "Family metadata confirmed."
          : "Family metadata submitted for review.",
      );
    } catch {
      setMessage("The family metadata could not be submitted.");
    }
  }

  return (
    <form onSubmit={(event) => void submit(event)}>
      <label htmlFor="photo-metadata-field">Metadata field</label>
      <select
        id="photo-metadata-field"
        value={field}
        onChange={(event) => {
          setField(
            event.target.value === "location" ? "location" : "historical_date",
          );
        }}
      >
        <option value="historical_date">Historical date</option>
        <option value="location">Human-supplied location</option>
      </select>
      <label>
        <input
          type="checkbox"
          checked={clears}
          onChange={(event) => {
            setClears(event.target.checked);
          }}
        />
        Clear the confirmed value
      </label>
      {!clears && field === "historical_date" && (
        <>
          <label htmlFor="photo-date-precision">Date precision</label>
          <select
            id="photo-date-precision"
            value={precision}
            onChange={(event) => {
              setPrecision(toPrecision(event.target.value));
              setValue("");
            }}
          >
            <option value="exact">Exact date</option>
            <option value="month">Month and year</option>
            <option value="year">Year</option>
            <option value="decade">Decade</option>
            <option value="approximate">Approximate date</option>
            <option value="unknown">Unknown</option>
          </select>
          {precision !== "unknown" && (
            <>
              <label htmlFor="photo-date-value">Date value</label>
              <input
                id="photo-date-value"
                value={value}
                placeholder={datePlaceholder(precision)}
                onChange={(event) => {
                  setValue(event.target.value);
                }}
                required
              />
            </>
          )}
        </>
      )}
      {!clears && field === "location" && (
        <>
          <label htmlFor="photo-location">Location as remembered</label>
          <input
            id="photo-location"
            value={location}
            onChange={(event) => {
              setLocation(event.target.value);
            }}
            placeholder="Grandma's house"
            maxLength={255}
            required
          />
        </>
      )}
      <button type="submit" disabled={pending}>
        {pending ? "Submitting…" : "Submit family metadata"}
      </button>
      {message !== "" && (
        <p role={message.includes("could not") ? "alert" : "status"}>
          {message}
        </p>
      )}
    </form>
  );
}

function toPrecision(value: string): DatePrecision {
  if (
    value === "month" ||
    value === "year" ||
    value === "decade" ||
    value === "approximate" ||
    value === "unknown"
  )
    return value;
  return "exact";
}

function datePlaceholder(precision: DatePrecision): string {
  if (precision === "month") return "1987-06";
  if (precision === "year") return "1987";
  if (precision === "decade") return "1980s";
  return "1987-06-14";
}
